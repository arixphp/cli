<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Contracts\RequestHandler;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Arix\Runtime\HttpRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HttpRuntimeTest extends TestCase
{
    #[DataProvider('supportedSapis')]
    #[Test]
    public function it_http_runtime_captures_request_and_runs_bootstrap(string $sapi): void
    {
        $server = $_SERVER;

        try {
            $_SERVER = ['REQUEST_METHOD' => 'QUERY', 'REQUEST_URI' => '/hook'];
            $handler = new class() implements RequestHandler
            {
                public function handle(Request $request, Container $container): Response
                {
                    return new Response($request->getMethod() . ':' . $request->attributes->get('_arix_path'));
                }
            };
            $response = (new HttpRuntime())->handle(static fn (): Kernel => new Kernel($handler), $sapi);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('QUERY:/hook', $response->getContent());
        } finally {
            $_SERVER = $server;
        }
    }

    public static function supportedSapis(): array
    {
        return [['apache2handler'], ['cgi-fcgi'], ['fpm-fcgi'], ['frankenphp'], ['litespeed']];
    }

    #[DataProvider('unsupportedSapis')]
    #[Test]
    public function it_unsupported_runtime_rejects_before_bootstrap(string $sapi): void
    {
        $called = false;
        $response = (new HttpRuntime())->handle(static function () use (&$called): void {
            $called = true;
        }, $sapi);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($called);
    }

    public static function unsupportedSapis(): array
    {
        return [['cli'], ['cli-server'], ['unknown']];
    }

    #[Test]
    public function it_bootstrap_failures_become_safe_500_responses_and_are_logged(): void
    {
        $logs = [];
        $runtime = new HttpRuntime(logger: static function (string $message) use (&$logs): void {
            $logs[] = $message;
        });
        $response = $runtime->handle(static function (): Kernel {
            throw new RuntimeException('bootstrap secret');
        }, 'fpm-fcgi');
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<pre>Internal server error.</pre>', $response->getContent());
        $this->assertStringNotContainsString('bootstrap secret', $response->getContent());
        $this->assertStringContainsString('bootstrap secret', $logs[0]);
    }

    #[Test]
    public function it_bootstrap_failure_for_head_does_not_emit_a_body(): void
    {
        $server = $_SERVER;

        try {
            $_SERVER = ['REQUEST_METHOD' => 'HEAD'];
            $runtime = new HttpRuntime(logger: static function (): void {});
            $response = $runtime->handle(static fn (): string => 'invalid kernel', 'apache2handler');
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('', $response->getContent());
        } finally {
            $_SERVER = $server;
        }
    }

    #[Test]
    public function it_cli_run_writes_only_stderr_and_does_not_execute_bootstrap(): void
    {
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; exit((new Arix\\Runtime\\HttpRuntime)->run(static function () { echo "BOOTSTRAP"; }));';
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(1, proc_close($process));
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('not supported', $stderr);
    }

    #[DataProvider('errorMethods')]
    #[Test]
    public function it_failing_logger_does_not_prevent_bootstrap_error_response(string $method, string $body): void
    {
        $server = $_SERVER;
        $log = tempnam(sys_get_temp_dir(), 'arix-log-');
        $previousLog = ini_set('error_log', $log);

        try {
            $_SERVER = ['REQUEST_METHOD' => $method];
            $runtime = new HttpRuntime(logger: static function (): void {
                throw new RuntimeException('logger failed');
            });
            $response = $runtime->handle(static function (): Kernel {
                throw new RuntimeException('bootstrap failed');
            }, 'fpm-fcgi');
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));

            if ($method === 'HEAD') {
                $this->assertSame('', $response->getContent());
            } else {
                $this->assertStringContainsString($body, $response->getContent());
            }
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('bootstrap failed', file_get_contents($log));
        } finally {
            $_SERVER = $server;
            ini_set('error_log', $previousLog);
            unlink($log);
        }
    }

    public static function errorMethods(): array
    {
        return [['GET', '<pre>Internal server error.</pre>'], ['HEAD', '']];
    }
}
