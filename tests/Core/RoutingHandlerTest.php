<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Facades\Route;
use Arix\Http\Kernel;
use Arix\Http\Routing\RouteRegistry;
use Arix\Http\Routing\RoutingHandler;
use Arix\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Test;
use stdClass;

class RoutingHandlerTest extends RoutingTestCase
{
    #[Test]
    public function it_routes_through_kernel_and_preserves_http_status_headers_and_head(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("id"), 201, ["X-Route" => "yes"]); }');
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class): void {
            $container->instance(RouteRegistry::class, new RouteRegistry);
            $container->bind($class, static fn (): object => new $class);
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
            $container->instance(RouteRegistry::class, new RouteRegistry);
            $container->bind($class, static fn (): object => new $class);
            Route::get('/articles/{slug}', [$class, 'show']);
        }, basePath: '/site');
        $this->assertSame('café', $kernel->handle(Request::create('/site/articles/caf%C3%A9'))->getContent());
        $this->assertSame('%2e%2e', $kernel->handle(Request::create('/site/articles/%252e%252e'))->getContent());
        $this->assertSame(400, $kernel->handle(Request::create('/site/articles/a%2Fb'))->getStatusCode());
        $this->assertSame(400, $kernel->handle(Request::create('/site/articles/%xx'))->getStatusCode());
        $this->assertSame(404, $kernel->handle(Request::create('/outside/articles/1'))->getStatusCode());
        $this->assertSame(404, $kernel->handle(Request::create('/site/articles/1/'))->getStatusCode());
    }

    #[Test]
    public function it_isolates_scopes_and_cleans_only_previous_route_parameters(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("year", "absent")); }');
        $scopes = [];
        $controllers = [];
        $kernel = new Kernel(new RoutingHandler($this->directory), configure: static function (Container $container) use ($class, &$scopes, &$controllers): void {
            $scopes[] = $container;
            $container->instance(RouteRegistry::class, new RouteRegistry);
            $container->bind($class, static function () use ($class, &$controllers): object {
                $controller = new $class;
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
            $container->instance(RouteRegistry::class, new RouteRegistry);
            $container->bind($class, static fn (): stdClass => new stdClass);
            Route::get('/', [$class, 'show']);
        }, logger: static function (): void {});
        $response = $kernel->handle(Request::create('/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Internal server error.', $response->getContent());
    }

    #[Test]
    public function it_runs_the_kernel_router_and_controller_without_composer(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("id")); }');
        $autoload = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'autoload.php';
        $program = 'require '.var_export($autoload, true).'; $class = '.var_export($class, true).'; $directory = '.var_export($this->directory, true).'; '.<<<'PHP'
            if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
            $kernel = new Arix\Http\Kernel(new Arix\Http\Routing\RoutingHandler($directory), configure: function ($container) use ($class) {
                $container->instance(Arix\Http\Routing\RouteRegistry::class, new Arix\Http\Routing\RouteRegistry);
                $container->bind($class, static fn () => new $class);
                Arix\Facades\Route::get('/articles/{id:\d+}', [$class, 'show']);
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
        $this->assertSame('200:42', $output);
    }
}
