<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\UnknownServiceException;
use Arix\Http\Routing\ControllerDispatcher;
use Arix\Http\Routing\RouteDefinition;
use Arix\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use stdClass;

use function strlen;

class ControllerDispatcherTest extends RoutingTestCase
{
    #[Test]
    public function it_loads_only_the_selected_controller_and_resolves_explicit_bindings(): void
    {
        $class = $this->controller('public function show(Request $request): Response { return new Response($request->attributes->get("slug"), 201); } public function index(): Response { return new Response("index"); }');
        $unused = $this->controller('public function show(): Response { throw new \\LogicException("unused"); }', 'UnusedController');
        $container = new Container;
        $request = Request::create('/article');
        $request->attributes->set('slug', 'article');
        $container->instance(Request::class, $request);
        $container->bind($class, function (Container $scope) use ($class, $request): object {
            $this->assertSame($request, $scope->get(Request::class));

            return new $class;
        });
        $dispatcher = new ControllerDispatcher($this->directory);
        $response = $dispatcher->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), $request, $container);
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('article', $response->getContent());
        $this->assertFalse(class_exists($unused, false));
        $this->assertSame('index', $dispatcher->dispatch(new RouteDefinition(['GET'], '/', $class, 'index'), $request, $container)->getContent());
    }

    #[Test]
    #[DataProvider('invalidActions')]
    public function it_rejects_invalid_action_contracts(string $body, string $action): void
    {
        $class = $this->controller($body);
        $container = new Container;
        $container->bind($class, static fn (): object => new $class);
        $this->expectException(RuntimeException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, $action), Request::create('/'), $container);
    }

    public static function invalidActions(): array
    {
        return [
            ['public function show(): Response { return new Response; }', 'missing'],
            ['private function show(): Response { return new Response; }', 'show'],
            ['public static function show(): Response { return new Response; }', 'show'],
            ['public function __invoke(): Response { return new Response; }', '__invoke'],
            ['public function show(string $value): Response { return new Response; }', 'show'],
            ['public function show(Request|null $request): Response { return new Response; }', 'show'],
            ['public function show(Request $request = new Request): Response { return new Response; }', 'show'],
            ['public function show(Request & $request): Response { return new Response; }', 'show'],
            ['public function show(Request ...$request): Response { return new Response; }', 'show'],
            ['public function show(Request $request, string $slug): Response { return new Response; }', 'show'],
            ['public function & show(): Response { $response = new Response; return $response; }', 'show'],
            ['public function show(): string { return "bad"; }', 'show'],
            ['public function show(): Response|null { return null; }', 'show'],
            ['public function show() { return new Response; }', 'show'],
            ['public function Show(): Response { return new Response; }', 'show'],
        ];
    }

    #[Test]
    public function it_accepts_response_subclass_declarations(): void
    {
        $class = $this->controller('public function show(): \\Arix\\HttpFoundation\\JsonResponse { return new \\Arix\\HttpFoundation\\JsonResponse(["ok" => true]); }');
        $container = new Container;
        $container->bind($class, static fn (): object => new $class);
        $response = new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), $container);
        $this->assertSame('{"ok":true}', $response->getContent());
    }

    #[Test]
    public function it_rejects_incompatible_bindings(): void
    {
        $class = $this->controller('public function show(): Response { return new Response; }');
        $container = new Container;
        $container->bind($class, static fn (): stdClass => new stdClass);
        $this->expectException(RuntimeException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), $container);
    }

    #[Test]
    public function it_does_not_construct_unbound_controllers(): void
    {
        $class = $this->controller('public function __construct() { throw new \\LogicException("must not construct"); } public function show(): Response { return new Response; }');
        $this->expectException(UnknownServiceException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), new Container);
    }

    #[Test]
    public function it_rejects_class_case_mismatches(): void
    {
        $class = $this->controller('public function show(): Response { return new Response; }');
        $this->expectException(RuntimeException::class);
        $wrongCase = str_replace('SampleController', 'sampleController', $class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $wrongCase, 'show'), Request::create('/'), new Container);
    }

    #[Test]
    public function it_rejects_symlinked_controller_directories(): void
    {
        $class = $this->controller('public function show(): Response { return new Response; }');
        $link = $this->directory.DIRECTORY_SEPARATOR.'linked';
        symlink(dirname($this->directory), $link);
        $this->expectException(RuntimeException::class);
        new ControllerDispatcher($link)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), new Container);
    }

    #[Test]
    public function it_rejects_linked_controller_files(): void
    {
        $class = $this->controller('public function show(): Response { return new Response; }');
        $file = $this->directory.DIRECTORY_SEPARATOR.str_replace('App\\Controllers\\', '', str_replace('\\SampleController', '', $class)).DIRECTORY_SEPARATOR.'SampleController.php';
        $outside = $this->directory.DIRECTORY_SEPARATOR.'outside.php';
        rename($file, $outside);
        symlink($outside, $file);
        $this->expectException(RuntimeException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), new Container);
    }

    #[Test]
    public function it_rejects_a_preloaded_class_from_a_different_file(): void
    {
        $class = $this->controller('public function show(): Response { return new Response; }');
        $namespace = substr($class, strlen('App\\Controllers\\'), -strlen('\\SampleController'));
        $file = $this->directory.DIRECTORY_SEPARATOR.$namespace.DIRECTORY_SEPARATOR.'SampleController.php';
        $other = $this->directory.DIRECTORY_SEPARATOR.'conflicting.php';
        copy($file, $other);
        require $other;
        $this->expectException(RuntimeException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), new Container);
    }

    #[Test]
    public function it_rejects_linked_nested_namespace_directories(): void
    {
        $class = $this->controller('public function show(): Response { return new Response; }');
        $namespace = substr($class, strlen('App\\Controllers\\'), -strlen('\\SampleController'));
        $nested = $this->directory.DIRECTORY_SEPARATOR.$namespace;
        $other = $this->directory.DIRECTORY_SEPARATOR.'other';
        rename($nested, $other);
        symlink($other, $nested);
        $this->expectException(RuntimeException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), new Container);
    }

    #[Test]
    public function it_renders_view_returns_as_html_responses(): void
    {
        $views = $this->views();
        file_put_contents($views.DIRECTORY_SEPARATOR.'page.arix.php', '<h1>{{ $title }}</h1>');
        $class = $this->controller('public function show(): \\Arix\\Views\\Contracts\\View { return new \\Arix\\Views\\View("page", ["title" => "Hi"]); }');
        $container = new Container;
        $container->bind($class, static fn (): object => new $class);
        $container->instance(\Arix\Views\TemplateEngine::class, new \Arix\Views\TemplateEngine($views, $this->cache()));
        $response = new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), $container);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<h1>Hi</h1>', $response->getContent());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_accepts_response_and_view_union_declarations(): void
    {
        $views = $this->views();
        file_put_contents($views.DIRECTORY_SEPARATOR.'page.arix.php', '<h1>{{ $title }}</h1>');
        $class = $this->controller('public function show(Request $request): \\Arix\\HttpFoundation\\Response|\\Arix\\Views\\Contracts\\View { return $request->attributes->get("asView") ? new \\Arix\\Views\\View("page", ["title" => "Hi"]) : new Response("plain"); }');
        $container = new Container;
        $container->bind($class, static fn (): object => new $class);
        $container->instance(\Arix\Views\TemplateEngine::class, new \Arix\Views\TemplateEngine($views, $this->cache()));
        $dispatcher = new ControllerDispatcher($this->directory);
        $plain = Request::create('/');
        $this->assertSame('plain', $dispatcher->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), $plain, $container)->getContent());
        $asView = Request::create('/');
        $asView->attributes->set('asView', true);
        $response = $dispatcher->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), $asView, $container);
        $this->assertSame('<h1>Hi</h1>', $response->getContent());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function it_rejects_nullable_view_declarations(): void
    {
        $class = $this->controller('public function show(): \\Arix\\Views\\Contracts\\View|null { return null; }');
        $container = new Container;
        $container->bind($class, static fn (): object => new $class);
        $this->expectException(RuntimeException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), $container);
    }

    #[Test]
    public function it_requires_view_services_for_view_returns(): void
    {
        $class = $this->controller('public function show(): \\Arix\\Views\\Contracts\\View { return new \\Arix\\Views\\View("page"); }');
        $container = new Container;
        $container->bind($class, static fn (): object => new $class);
        $this->expectException(UnknownServiceException::class);
        new ControllerDispatcher($this->directory)->dispatch(new RouteDefinition(['GET'], '/', $class, 'show'), Request::create('/'), $container);
    }

    private function views(): string
    {
        $views = $this->directory.DIRECTORY_SEPARATOR.'views';

        if (! is_dir($views)) {
            mkdir($views, 0755, true);
        }

        return $views;
    }

    private function cache(): string
    {
        $cache = $this->directory.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR;

        if (! is_dir($cache)) {
            mkdir($cache, 0755, true);
        }

        return $cache;
    }
}
