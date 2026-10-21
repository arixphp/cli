<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Http\HttpException;
use Arix\Routing\Router;
use Arix\Routing\RouteRegistry;
use Arix\Routing\RouteUrlGenerator;
use Arix\Routing\UrlGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RouteParameterTest extends TestCase
{
    private function generator(string $pattern = '/blog/{slug}'): RouteUrlGenerator
    {
        $registry = new RouteRegistry();
        $registry->get($pattern, ['App\\Controllers\\UnloadedController', 'show'])->name('blog');

        return new RouteUrlGenerator($registry, new UrlGenerator('', '/'));
    }

    #[Test]
    public function it_generates_urls_for_a_default_constraint(): void
    {
        $this->assertSame('/blog/hola-mundo', $this->generator()->to('blog', ['slug' => 'hola-mundo']));
        $this->assertSame('/blog/cafe', $this->generator()->to('blog', ['slug' => 'cafe']));
    }

    #[Test]
    public function it_generates_urls_for_an_explicit_constraint(): void
    {
        $this->assertSame('/blog/42', $this->generator('/blog/{id:\d+}')->to('blog', ['id' => '42']));
    }

    #[Test]
    #[DataProvider('unsafeParameters')]
    public function it_refuses_to_generate_a_url_from_an_unsafe_parameter(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->to('blog', ['slug' => $value]);
    }

    public static function unsafeParameters(): array
    {
        return array_map(static fn (string $value): array => [$value], [
            '..', '.', '../../admin', 'a/b', 'a\\b', '', "a\0b",
        ]);
    }

    #[Test]
    public function it_encodes_a_parameter_that_is_not_a_path_segment(): void
    {
        $this->assertSame('/blog/a%252Fb', $this->generator()->to('blog', ['slug' => 'a%2Fb']));
        $this->assertSame('/blog/a%20b', $this->generator()->to('blog', ['slug' => 'a b']));
    }

    #[Test]
    public function it_does_not_match_a_capture_that_crosses_a_segment_boundary(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/files/{path:.*}', ['App\\Controllers\\UnloadedController', 'show']);
        $router = new Router($registry);

        $this->assertSame(['path' => 'one'], $router->resolve('GET', '/files/one')->parameters);

        $this->expectException(HttpException::class);
        $router->resolve('GET', '/files/a/b');
    }

    #[Test]
    public function it_does_not_match_an_unsafe_capture(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/files/{path:.+}', ['App\\Controllers\\UnloadedController', 'show']);
        $router = new Router($registry);

        $this->expectException(HttpException::class);
        $router->resolve('GET', '/files/..');
    }
}
