<?php

declare(strict_types=1);

namespace Tests\Core;

use ArgumentCountError;
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
use Tests\Support\StandaloneCore;
use Tests\Support\TempTree;

class HttpRuntimeTest extends TestCase
{
    #[Test]
    public function it_rejects_a_missing_manifest_before_executing_the_bootstrap(): void
    {
        unlink($this->tree->path('.arix.json'));
        $called = false;
        $response = (new HttpRuntime($this->tree->root, logger: static function (): void {}))->handle(static function () use (&$called): void {
            $called = true;
        }, 'fpm-fcgi');
        $this->assertFalse($called);
        $this->assertSame(500, $response->getStatusCode());
    }

    private TempTree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new TempTree();
        $this->tree->applicationManifest();
        $this->tree->write('.env', 'APP_DEBUG=false');
    }

    protected function tearDown(): void
    {
        $this->tree->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_requires_the_application_root(): void
    {
        $this->expectException(ArgumentCountError::class);
        new HttpRuntime();
    }

    #[Test]
    public function it_loads_environment_before_bootstrap_and_keeps_it_during_dispatch(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $tree->write('.env', "APP_RUNTIME_TEST=file\nAPP_DEBUG=true");
        $before = $_ENV;
        $server = $_SERVER;

        try {
            $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
            $runtime = new HttpRuntime(applicationRoot: $tree->root);
            $response = $runtime->handle(function (): Kernel {
                $this->assertSame('file', $_ENV['APP_RUNTIME_TEST']);

                return new Kernel(new class() implements RequestHandler
                {
                    public function handle(Request $request, Container $container): Response
                    {

                        return new Response($_ENV['APP_RUNTIME_TEST']);
                    }
                });
            }, 'fpm-fcgi');
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('file', $response->getContent());
            $this->assertSame($before, $_ENV);
        } finally {
            $_SERVER = $server;
            $tree->cleanup();
        }
    }

    #[Test]
    public function it_resolves_debug_only_from_the_application_environment(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $file = $tree->write('.env', 'APP_DEBUG=true');
        $bootstrap = static function (): never {
            throw new RuntimeException('visible-bootstrap-detail');
        };

        try {
            $runtime = new HttpRuntime(logger: static function (): void {}, applicationRoot: $tree->root);
            $this->assertStringContainsString('visible-bootstrap-detail', $runtime->handle($bootstrap, 'fpm-fcgi')->getContent());
            file_put_contents($file, 'APP_DEBUG=invalid');
            $this->assertStringNotContainsString('visible-bootstrap-detail', $runtime->handle($bootstrap, 'fpm-fcgi')->getContent());
            file_put_contents($file, 'APP_DEBUG=false');
            $this->assertStringNotContainsString('visible-bootstrap-detail', $runtime->handle($bootstrap, 'fpm-fcgi')->getContent());
        } finally {
            $tree->cleanup();
        }
    }

    #[Test]
    public function it_handles_environment_failures_without_debug_or_secret_leaks(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $tree->write('.env', "APP_DEBUG=true\nsecret-invalid-assignment");
        $original = getenv('APP_DEBUG');
        putenv('APP_DEBUG=true');
        $called = false;

        try {
            $runtime = new HttpRuntime($tree->root, static function (): void {});
            $response = $runtime->handle(static function () use (&$called): void {
                $called = true;
            }, 'fpm-fcgi');
            $this->assertFalse($called);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString('secret-invalid-assignment', $response->getContent());
            $this->assertStringNotContainsString('line 2', $response->getContent());
            $this->assertStringContainsString('Internal server error.', $response->getContent());
        } finally {
            putenv($original === false ? 'APP_DEBUG' : "APP_DEBUG={$original}");
            $tree->cleanup();
        }
    }

    #[Test]
    public function it_rejects_unsupported_sapis_before_inspecting_the_environment_root(): void
    {
        $runtime = new HttpRuntime(applicationRoot: 'invalid://root');
        $response = $runtime->handle(static function (): never {
            throw new RuntimeException('must not execute');
        }, 'cli-server');
        $this->assertSame(503, $response->getStatusCode());
    }

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
            $response = (new HttpRuntime($this->tree->root))->handle(static fn (): Kernel => new Kernel($handler), $sapi);
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
        $response = (new HttpRuntime($this->tree->root))->handle(static function () use (&$called): void {
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
        $runtime = new HttpRuntime($this->tree->root, logger: static function (string $message) use (&$logs): void {
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
            $runtime = new HttpRuntime($this->tree->root, logger: static function (): void {});
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
        $autoload = StandaloneCore::autoload();
        $program = 'require ' . var_export($autoload, true) . '; exit((new Arix\\Runtime\\HttpRuntime("invalid://root"))->run(static function () { echo "BOOTSTRAP"; }));';
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
            $runtime = new HttpRuntime($this->tree->root, logger: static function (): void {
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
