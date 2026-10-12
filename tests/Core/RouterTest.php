<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Constants\HttpStatusCode;
use Arix\Http\HttpException;
use Arix\Routing\Router;
use Arix\Routing\RouteRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private const array HANDLER = ['App\\Controllers\\UnloadedController', 'show'];

    #[Test]
    public function it_matches_constraints_and_optional_parameters(): void
    {
        $registry = new RouteRegistry();
        $numeric = $registry->get('/articles/{id:\d+}', self::HANDLER);
        $registry->get('/archive[/{year:\d+}]', self::HANDLER);
        $router = new Router($registry);
        $this->assertSame($numeric, $router->resolve('GET', '/articles/42')->route);
        $this->assertSame(['id' => '42'], $router->resolve('GET', '/articles/42')->parameters);
        $this->assertSame([], $router->resolve('GET', '/archive')->parameters);
        $this->assertSame(['year' => '2026'], $router->resolve('GET', '/archive/2026')->parameters);
        $this->expectException(HttpException::class);

        try {
            $router->resolve('GET', '/articles/nope');
        } catch (HttpException $error) {
            $this->assertSame(HttpStatusCode::NOT_FOUND, $error->status);

            throw $error;
        }
    }

    #[Test]
    public function it_preserves_explicit_head_and_options_and_implicit_fallbacks(): void
    {
        $registry = new RouteRegistry();
        $get = $registry->get('/implicit/{id}', self::HANDLER);
        $registry->get('/explicit/{id}', self::HANDLER);
        $head = $registry->head('/explicit/{id}', self::HANDLER);
        $options = $registry->options('/explicit/{id}', self::HANDLER);
        $router = new Router($registry);
        $this->assertSame($head, $router->resolve('HEAD', '/explicit/1')->route);
        $this->assertSame($options, $router->resolve('OPTIONS', '/explicit/1')->route);
        $this->assertSame($get, $router->resolve('HEAD', '/implicit/1')->route);
        $this->assertNull($router->resolve('OPTIONS', '/implicit/1')->route);
        $this->assertSame(['GET', 'HEAD', 'OPTIONS'], $router->resolve('OPTIONS', '/implicit/1')->allowedMethods);
        $this->expectException(HttpException::class);

        try {
            $router->resolve('OPTIONS', '/unknown');
        } catch (HttpException $error) {
            $this->assertSame(HttpStatusCode::NOT_FOUND, $error->status);

            throw $error;
        }
    }

    #[Test]
    public function it_computes_allow_for_concrete_urls_without_a_sentinel_method(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/articles/{id:\d+}', self::HANDLER);
        $registry->post('/articles/{slug}', self::HANDLER);
        $registry->query('/articles/{id:\d+}', self::HANDLER);
        $registry->match(['ARIX-PROBE', 'ZZZ'], '/articles/{id:\d+}', self::HANDLER);
        $router = new Router($registry);
        $this->assertSame(['GET', 'HEAD', 'POST', 'OPTIONS', 'QUERY', 'ARIX-PROBE', 'ZZZ'], $router->resolve('OPTIONS', '/articles/42')->allowedMethods);
        $this->assertSame(['POST', 'OPTIONS'], $router->resolve('OPTIONS', '/articles/news')->allowedMethods);
        $this->assertSame(['id' => '42'], $router->resolve('QUERY', '/articles/42')->parameters);
        $this->assertSame(['id' => '42'], $router->resolve('ARIX-PROBE', '/articles/42')->parameters);
        $this->expectException(HttpException::class);

        try {
            $router->resolve('DELETE', '/articles/news');
        } catch (HttpException $error) {
            $this->assertSame(HttpStatusCode::METHOD_NOT_ALLOWED, $error->status);
            $this->assertSame(['Allow' => 'POST, OPTIONS'], $error->headers);

            throw $error;
        }
    }

    #[Test]
    public function it_preserves_static_priority_and_exact_trailing_slashes(): void
    {
        $registry = new RouteRegistry();
        $static = $registry->get('/articles/new', self::HANDLER);
        $registry->get('/articles/{slug}', self::HANDLER);
        $router = new Router($registry);
        $this->assertSame($static, $router->resolve('GET', '/articles/new')->route);
        $this->expectException(HttpException::class);
        $router->resolve('GET', '/articles/new/');
    }
}
