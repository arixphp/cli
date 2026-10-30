<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Contracts\Middleware;
use Arix\Facades\Route;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Arix\Routing\RouteRegistry;
use Arix\Routing\RoutingHandler;
use Arix\Views\ViewServices;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class RoutingHandlerTest extends RoutingTestCase
{
    #[Test]
    public function it_runs_matched_route_middleware_around_the_controller(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("marker")); }');
        $middleware = new class() implements Middleware
        {
            public function handle(Request $request, \Closure $next): Response
            {
                $request->attributes->set('marker', $request->attributes->get('id'));

                return $next($request);
            }
        };
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class, $middleware): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->instance('Tests\\Core\\RouteMiddleware', $middleware);
            $container->bind($class, static fn (): object => new $class());
            Route::get('/articles/{id:\d+}', [$class, 'show'])->middleware('Tests\\Core\\RouteMiddleware');
        });

        $this->assertSame('42', $kernel->handle(Request::create('/articles/42'))->getContent());
    }

    #[Test]
    public function it_allows_route_middleware_to_short_circuit_only_matched_routes(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("not reached"); }');
        $calls = 0;
        $middleware = new class($calls) implements Middleware
        {
            public function __construct(private int &$calls) {}

            public function handle(Request $request, \Closure $next): Response
            {
                $this->calls++;

                return new Response('blocked', 403);
            }
        };
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class, $middleware): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->instance('Tests\\Core\\BlockingRouteMiddleware', $middleware);
            $container->bind($class, static fn (): object => new $class());
            Route::get('/protected', [$class, 'show'])->middleware('Tests\\Core\\BlockingRouteMiddleware');
        });

        $this->assertSame('blocked', $kernel->handle(Request::create('/protected'))->getContent());
        $this->assertSame(404, $kernel->handle(Request::create('/missing'))->getStatusCode());
        $this->assertSame(405, $kernel->handle(Request::create('/protected', 'POST'))->getStatusCode());
        $this->assertSame(204, $kernel->handle(Request::create('/protected', 'OPTIONS'))->getStatusCode());
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function it_converts_route_middleware_exceptions_to_safe_kernel_errors(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("not reached"); }');
        $middleware = new class() implements Middleware
        {
            public function handle(Request $request, \Closure $next): Response
            {
                throw new \RuntimeException('route middleware secret');
            }
        };
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class, $middleware): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->instance('Tests\\Core\\ThrowingRouteMiddleware', $middleware);
            $container->bind($class, static fn (): object => new $class());
            Route::get('/failing', [$class, 'show'])->middleware('Tests\\Core\\ThrowingRouteMiddleware');
        }, logger: static function (): void {});

        $response = $kernel->handle(Request::create('/failing'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('route middleware secret', $response->getContent());
    }

    #[Test]
    public function it_routes_through_kernel_and_preserves_http_status_headers_and_head(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("id"), 201, ["X-Route" => "yes"]); }');
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->bind($class, static fn (): object => new $class());
            Route::get('/articles/{id:\d+}', [$class, 'show']);
            Route::query('/articles/{id:\d+}', [$class, 'show']);
        });
        $response = $kernel->handle(Request::create('/articles/42?q=ignored'));
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('42', $response->getContent());
        $this->assertSame('yes', $response->headers->get('X-Route'));
        $this->assertSame('', $kernel->handle(Request::create('/articles/42', 'HEAD'))->getContent());
        $options = $kernel->handle(Request::create('/articles/42', 'OPTIONS'));
        $this->assertSame(204, $options->getStatusCode());
        $this->assertSame('GET, HEAD, OPTIONS, QUERY', $options->headers->get('Allow'));
        $this->assertSame('', $options->getContent());
        $this->assertSame('42', $kernel->handle(Request::create('/articles/42', 'QUERY'))->getContent());
        $error = $kernel->handle(Request::create('/articles/42', 'POST'));
        $this->assertSame(405, $error->getStatusCode());
        $this->assertSame('GET, HEAD, OPTIONS, QUERY', $error->headers->get('Allow'));
        $this->assertSame(404, $kernel->handle(Request::create('/missing'))->getStatusCode());
    }

    #[Test]
    public function it_preserves_single_decoding_and_base_path_validation(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("slug")); }');
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->bind($class, static fn (): object => new $class());
            Route::get('/articles/{slug}', [$class, 'show']);
        });
        $request = static fn (string $uri): Request => Request::create($uri, server: ['SCRIPT_NAME' => '/site/index.php', 'SCRIPT_FILENAME' => '/srv/index.php']);
        $this->assertSame('café', $kernel->handle($request('/site/articles/caf%C3%A9'))->getContent());
        $this->assertSame(400, $kernel->handle($request('/site/articles/a%2Fb'))->getStatusCode());
        $this->assertSame(400, $kernel->handle($request('/site/articles/%xx'))->getStatusCode());
        $this->assertSame(404, $kernel->handle($request('/outside/articles/1'))->getStatusCode());
        $this->assertSame(404, $kernel->handle($request('/site/articles/1/'))->getStatusCode());
    }

    #[Test]
    public function it_decodes_a_request_path_exactly_once(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("slug")); }');
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->bind($class, static fn (): object => new $class());
            Route::get('/articles/{slug}', [$class, 'show']);
        });

        // A decoded path that still carries an escape is malformed, not a route.
        $this->assertSame(400, $kernel->handle(Request::create('/articles/%252e%252e'))->getStatusCode());
        $this->assertSame(400, $kernel->handle(Request::create('/articles/100%25'))->getStatusCode());

        // Characters that are legal in a path segment must not fail the request.
        $this->assertSame('a:b', $kernel->handle(Request::create('/articles/a%3Ab'))->getContent());
        $this->assertSame('a?b', $kernel->handle(Request::create('/articles/a%3Fb'))->getContent());
    }

    #[Test]
    public function it_isolates_scopes_and_cleans_only_previous_route_parameters(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("year", "absent")); }');
        $scopes = [];
        $controllers = [];
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class, &$scopes, &$controllers): void {
            $scopes[] = $container;
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->bind($class, static function () use ($class, &$controllers): object {
                $controller = new $class();
                $controllers[] = $controller;

                return $controller;
            });
            Route::get('/archive[/{year:\d+}]', [$class, 'show']);
        });
        $request = Request::create('/archive/2026');
        $request->attributes->set('unrelated', 'keep');
        $this->assertSame('2026', $kernel->handle($request)->getContent());
        $request->initialize([], [], $request->attributes->all(), [], [], ['REQUEST_URI' => '/archive', 'REQUEST_METHOD' => 'GET'], '');
        $this->assertSame('absent', $kernel->handle($request)->getContent());
        $this->assertFalse($request->attributes->has('year'));
        $this->assertSame('keep', $request->attributes->get('unrelated'));
        $this->assertSame('/archive', $request->attributes->get('_arix_path'));
        $this->assertNotSame($scopes[0], $scopes[1]);
        $this->assertNotSame($controllers[0], $controllers[1]);
    }

    #[Test]
    public function it_converts_invalid_bindings_to_safe_kernel_errors(): void
    {
        $class = $this->controller('public function show(): Response { return new Response; }');
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->bind($class, static fn (): stdClass => new stdClass());
            Route::get('/', [$class, 'show']);
        }, logger: static function (): void {});
        $response = $kernel->handle(Request::create('/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<pre>Internal server error.</pre>', $response->getContent());
    }

    #[Test]
    public function it_runs_the_kernel_router_and_controller_without_composer(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("id") . ":" . $request->attributes->get("middleware")); }');
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; $class = ' . var_export($class, true) . '; $directory = ' . var_export($this->directory, true) . '; ' . <<<'PHP'
            if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
            $kernel = new Arix\Http\Kernel(new Arix\Routing\RoutingHandler($directory), configure: function ($container) use ($class) {
                $container->instance(Arix\Routing\RouteRegistry::class, new Arix\Routing\RouteRegistry);
                $container->instance('StandaloneMiddleware', new class implements Arix\Contracts\Middleware {
                    public function handle(Arix\HttpFoundation\Request $request, Closure $next): Arix\HttpFoundation\Response {
                        $request->attributes->set('middleware', 'yes');

                        return $next($request);
                    }
                });
                $container->bind($class, static fn () => new $class);
                Arix\Facades\Route::get('/articles/{id:\d+}', [$class, 'show'])->middleware('StandaloneMiddleware');
            });
            $response = $kernel->handle(Arix\HttpFoundation\Request::create('/articles/42'));
            echo $response->getStatusCode().':'.$response->getContent();
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('200:42:yes', $output);
    }

    #[Test]
    public function it_renders_views_through_the_kernel_and_suppresses_head_bodies(): void
    {
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'views', 0755, true);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'lang', 0755, true);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', 0755, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'page.arix.php', '<h1>{{ $title }}</h1>');
        $root = $this->directory;
        $class = $this->controller('public function show(): \\Arix\\Views\\Contracts\\View { return new \\Arix\\Views\\View("page", ["title" => "Hi"]); }');
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class, $root): void {
            $container->instance(RouteRegistry::class, new RouteRegistry());
            $container->bind($class, static fn (): object => new $class());
            ViewServices::register($container, $root, [
                'app' => ['name' => 'Site', 'base_path' => '', 'locales' => ['en'], 'default_locale' => 'en'],
                'cache' => ['version' => '1'],
                'views' => $root . DIRECTORY_SEPARATOR . 'views',
                'lang' => $root . DIRECTORY_SEPARATOR . 'lang',
            ]);
            Route::get('/page', [$class, 'show']);
        });
        $response = $kernel->handle(Request::create('/page'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<h1>Hi</h1>', $response->getContent());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('', $kernel->handle(Request::create('/page', 'HEAD'))->getContent());
    }
}
