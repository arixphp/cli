<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Contracts\RequestHandler;
use Arix\Http\HttpException;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class KernelTest extends TestCase
{
    #[Test]
    public function it_each_request_has_its_own_container_request_and_singletons(): void
    {
        $scopes = [];
        $kernel = new Kernel($this->handler(function (Request $request, Container $container) use (&$scopes): Response {
            $this->assertSame($container, ContainerContext::current());
            $this->assertSame($request, $container->get(Request::class));
            $this->assertSame($container->get('shared'), $container->get('shared'));
            $scopes[] = [$container, $container->get('shared'), $request];

            return new Response($request->attributes->get('_arix_path'), 201, ['X-Arix' => 'ok']);
        }), configure: static function (Container $container): void {
            $container->singleton('shared', static fn (): stdClass => new stdClass);
        });
        $first = $kernel->handle(Request::create('/first'));
        $second = $kernel->handle(Request::create('/second'));
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame('ok', $first->headers->get('X-Arix'));
        $this->assertSame('/second', $second->getContent());
        $this->assertNotSame($scopes[0][0], $scopes[1][0]);
        $this->assertNotSame($scopes[0][1], $scopes[1][1]);
        $this->assertNotSame($scopes[0][2], $scopes[1][2]);
        $this->expectException(LogicException::class);
        ContainerContext::current();
    }

    #[DataProvider('emptyResponses')]
    #[Test]
    public function it_preparation_suppresses_head_and_empty_status_bodies(string $method, int $status): void
    {
        $kernel = new Kernel($this->handler(static fn (): Response => new Response('must not emit', $status)));
        $response = $kernel->handle(Request::create('/', $method));
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public static function emptyResponses(): array
    {
        return [['HEAD', 200], ['GET', 204], ['GET', 304]];
    }

    #[Test]
    public function it_http_errors_preserve_status_and_headers_without_caching(): void
    {
        $kernel = new Kernel($this->handler(static function (): Response {
            throw new HttpException(405, 'Method not allowed.', ['Allow' => 'GET, HEAD']);
        }));
        $response = $kernel->handle(Request::create('/'));
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD', $response->headers->get('Allow'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[Test]
    public function it_unexpected_errors_are_logged_and_hidden_in_production(): void
    {
        $logs = [];
        $kernel = new Kernel($this->handler(static function (): Response {
            throw new RuntimeException('secret-password');
        }), logger: static function (string $message) use (&$logs): void {
            $logs[] = $message;
        });
        $response = $kernel->handle(Request::create('/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal server error.', $response->getContent());
        $this->assertStringContainsString('secret-password', $logs[0]);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->expectException(LogicException::class);
        ContainerContext::current();
    }

    #[Test]
    public function it_configuration_exceptions_are_also_converted_to_500(): void
    {
        $kernel = new Kernel($this->handler(static fn (): Response => new Response('not reached')),
            configure: static function (): void {
                throw new RuntimeException('config secret');
            },
            logger: static function (): void {});
        $this->assertSame(500, $kernel->handle(Request::create('/'))->getStatusCode());
    }

    #[Test]
    public function it_debug_errors_are_plain_text_and_include_diagnostics(): void
    {
        $kernel = new Kernel($this->handler(static function (): Response {
            throw new RuntimeException('<script>debug</script>');
        }),
            debug: true, logger: static function (): void {});
        $response = $kernel->handle(Request::create('/'));
        $this->assertStringContainsString('<script>debug</script>', $response->getContent());
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_bad_request_exceptions_from_http_foundation_become_400(): void
    {
        $kernel = new Kernel($this->handler(static function (Request $request): Response {
            $request->query->get('x');

            return new Response('not reached');
        }));
        $this->assertSame(400, $kernel->handle(Request::create('/?x[]=1'))->getStatusCode());
    }

    #[Test]
    public function it_oversized_injected_bodies_do_not_reach_configuration_or_handler(): void
    {
        $kernel = new Kernel($this->handler(static function (): Response {
            throw new LogicException('Handler must not run.');
        }),
            configure: static function (): void {
                throw new LogicException('Configuration must not run.');
            },
            maxBodyBytes: 4);
        $response = $kernel->handle(Request::create('/', 'POST', content: '12345'));
        $this->assertSame(413, $response->getStatusCode());
    }

    #[Test]
    public function it_invalid_paths_are_rejected_before_dispatch(): void
    {
        $kernel = new Kernel($this->handler(static function (): Response {
            throw new LogicException('Handler must not run.');
        }));
        $this->assertSame(400, $kernel->handle(Request::create('/%2e%2e'))->getStatusCode());
    }

    #[Test]
    public function it_injected_streams_are_bounded_before_any_unlimited_read(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, '123456789');
        rewind($stream);

        try {
            $request = Request::create('/', 'POST', content: $stream);
            $kernel = new Kernel($this->handler(static fn (): Response => new Response('not reached')), maxBodyBytes: 4);
            $this->assertSame(413, $kernel->handle($request)->getStatusCode());
            $this->assertSame(5, ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function it_within_limit_injected_stream_is_captured_as_repeatable_bytes(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, 'data');
        rewind($stream);

        try {
            $request = Request::create('/hook', 'POST', content: $stream);
            $kernel = new Kernel($this->handler(static function (Request $request) use ($stream): Response {
                // Changing the original stream must not change the captured request.
                ftruncate($stream, 0);

                return new Response($request->getContent().':'.$request->getContent());
            }), maxBodyBytes: 4);
            $this->assertSame('data:data', $kernel->handle($request)->getContent());
        } finally {
            fclose($stream);
        }
    }

    #[DataProvider('errorMethods')]
    #[Test]
    public function it_failing_logger_does_not_prevent_a_safe_error_response(string $method, string $body): void
    {
        $log = tempnam(sys_get_temp_dir(), 'arix-log-');
        $previousLog = ini_set('error_log', $log);

        try {
            $kernel = new Kernel($this->handler(static function (): Response {
                throw new RuntimeException('application failed');
            }),
                logger: static function (): void {
                    throw new RuntimeException('logger failed');
                });
            $response = $kernel->handle(Request::create('/', $method));
            $this->assertSame(500, $response->getStatusCode());
            $this->assertSame($body, $response->getContent());
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertStringContainsString('application failed', file_get_contents($log));
        } finally {
            ini_set('error_log', $previousLog);
            unlink($log);
        }
    }

    public static function errorMethods(): array
    {
        return [['GET', 'Internal server error.'], ['HEAD', '']];
    }

    #[Test]
    public function it_malformed_injected_request_still_receives_a_safe_error_response(): void
    {
        $kernel = new Kernel($this->handler(static fn (): Response => new Response('body')),
            logger: static function (): void {});
        $request = new Request(server: ['REQUEST_URI' => '/', 'REQUEST_METHOD' => []], content: '');
        $response = $kernel->handle($request);
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal server error.', $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->expectException(LogicException::class);
        ContainerContext::current();
    }

    private function handler(Closure $callback): RequestHandler
    {
        return new class($callback) implements RequestHandler
        {
            public function __construct(private readonly Closure $callback) {}

            public function handle(Request $request, Container $container): Response
            {
                return ($this->callback)($request, $container);
            }
        };
    }
}
