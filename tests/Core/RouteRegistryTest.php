<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\FastRoute\BadRouteException;
use Arix\Routing\RouteRegistry;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

class RouteRegistryTest extends TestCase
{
    #[Test]
    public function it_assigns_middleware_fluently_before_route_compilation(): void
    {
        $routes = new RouteRegistry();
        $route = $routes->get('/middleware', ['App\\Controllers\\ProbeController', 'show'])
            ->middleware(['App\\Middleware\\First', 'App\\Middleware\\Second']);

        $this->assertSame(['App\\Middleware\\First', 'App\\Middleware\\Second'], $route->middleware);
    }

    #[Test]
    public function it_rejects_middleware_changes_after_route_compilation(): void
    {
        $routes = new RouteRegistry();
        $route = $routes->get('/locked-middleware', ['App\\Controllers\\ProbeController', 'show']);
        $routes->dispatcher();

        $this->expectException(LogicException::class);
        $route->middleware('App\\Middleware\\Late');
    }

    #[Test]
    public function it_registers_dynamic_routes_without_loading_controllers(): void
    {
        $registry = new RouteRegistry();
        $route = $registry->get('/archive[/{year:\d+}]', ['App\\Controllers\\UnloadedController', 'show']);
        $dispatcher = $registry->dispatcher();
        $this->assertSame([1, $route, ['year' => '2026']], $dispatcher->dispatch('GET', '/archive/2026'));
        $this->assertSame([1, $route, []], $dispatcher->dispatch('GET', '/archive'));
        $this->assertFalse(class_exists('App\\Controllers\\UnloadedController', false));
        $this->assertSame($dispatcher, $registry->dispatcher());
        $this->assertSame(['GET'], $registry->methods());
        $this->expectException(LogicException::class);
        $registry->post('/later', ['App\\Controllers\\UnloadedController', 'show']);
    }

    #[Test]
    #[DataProvider('invalidRegistrations')]
    public function it_rejects_invalid_configuration(array $methods, string $path, array $handler): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RouteRegistry()->match($methods, $path, $handler);
    }

    public static function invalidRegistrations(): array
    {
        $handler = ['App\\Controllers\\UnloadedController', 'show'];

        return [
            [[], '/', $handler],
            [['GET', 'GET'], '/', $handler],
            [['*'], '/', $handler],
            [['BAD METHOD'], '/', $handler],
            [['GET'], 'relative', $handler],
            [['GET'], '/literal?query', $handler],
            [['GET'], '/literal#fragment', $handler],
            [['GET'], '/{_arix_path}', $handler],
            [['GET'], '/{_arix_route_parameters}', $handler],
            [['GET'], '/', ['Other\\Controller', 'show']],
            [['GET'], '/', ['App\\Controllers\\UnloadedController', '__call']],
            [['GET'], '/', ['App\\Controllers\\UnloadedController']],
        ];
    }

    #[Test]
    public function it_preserves_constraint_regex_syntax_instead_of_treating_it_as_uri_metadata(): void
    {
        foreach ([['/items/{id:(?:foo|bar)}', '/items/foo', 'foo'], ['/items/{id:ab?}', '/items/a', 'a'], ['/items/{id:[a-z#]+}', '/items/a#b', 'a#b']] as [$pattern, $path, $value]) {
            $registry = new RouteRegistry();
            $registry->get($pattern, ['App\\Controllers\\UnloadedController', 'show']);
            $this->assertSame(['id' => $value], $registry->dispatcher()->dispatch('GET', $path)[2]);
        }
    }

    #[Test]
    #[DataProvider('conflictingPatterns')]
    public function it_rejects_duplicate_and_invalid_fastroute_patterns(string $pattern): void
    {
        $registry = new RouteRegistry();
        $registry->get('/same', ['App\\Controllers\\UnloadedController', 'show']);
        $this->expectException(BadRouteException::class);
        $registry->get($pattern, ['App\\Controllers\\UnloadedController', 'show']);
    }

    public static function conflictingPatterns(): array
    {
        return [['/same'], ['/{id}/{id}'], ['/archive[/{year}]/extra']];
    }

    #[Test]
    public function it_rejects_conflicts_without_corrupting_previous_registration(): void
    {
        $registry = new RouteRegistry();
        $first = $registry->get('/{id}', ['App\\Controllers\\UnloadedController', 'show']);
        $failed = false;

        try {
            $registry->match(['POST', 'GET'], '/{slug}', ['App\\Controllers\\UnloadedController', 'show']);
        } catch (Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed);
        $this->assertSame(['GET'], $registry->methods());
        $this->assertSame($first, $registry->dispatcher()->dispatch('GET', '/value')[1]);
        $this->assertSame(2, $registry->dispatcher()->dispatch('POST', '/value')[0]);
    }
}
