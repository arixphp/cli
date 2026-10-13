<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Facades\Route;
use Arix\Routing\RouteRegistry;
use Arix\Routing\RouteResolver;
use Arix\Routing\RouteUrlGenerator;
use Arix\Routing\UrlGenerator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class NamedRouteTest extends TestCase
{
    #[Test]
    public function it_assigns_name_fluently(): void
    {
        $registry = new RouteRegistry();
        $route = $registry->get('/about', ['App\\Controllers\\UnloadedController', 'show'])->name('about');

        $this->assertSame('about', $route->name);
        $this->assertSame($route, $registry->named('about'));
        $this->assertTrue($registry->has('about'));
        $this->assertFalse($registry->has('missing'));
    }

    #[Test]
    public function it_rejects_invalid_names(): void
    {
        $registry = new RouteRegistry();
        $route = $registry->get('/about', ['App\\Controllers\\UnloadedController', 'show']);
        $caught = 0;

        foreach (['', 'con espacio', '-guion', 'a/b', '_arix_x'] as $name) {
            try {
                $route->name($name);
            } catch (InvalidArgumentException) {
                $caught++;
            }
        }

        $this->assertSame(5, $caught);
    }

    #[Test]
    public function it_rejects_duplicate_names(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/one', ['App\\Controllers\\UnloadedController', 'show'])->name('same');
        $registry->get('/two', ['App\\Controllers\\UnloadedController', 'show'])->name('same');

        $this->expectException(LogicException::class);
        $registry->dispatcher();
    }

    #[Test]
    public function it_rejects_rename_after_compilation(): void
    {
        $registry = new RouteRegistry();
        $route = $registry->get('/about', ['App\\Controllers\\UnloadedController', 'show'])->name('about');
        $registry->dispatcher();

        $this->expectException(LogicException::class);
        $route->name('other');
    }

    #[Test]
    public function it_generates_static_and_dynamic_urls(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/about', ['App\\Controllers\\UnloadedController', 'show'])->name('about');
        $registry->get('/articles/{id:\d+}', ['App\\Controllers\\UnloadedController', 'show'])->name('article');
        $generator = new RouteUrlGenerator($registry, new UrlGenerator('/sub', '/'));

        $this->assertSame('/sub/about', $generator->to('about'));
        $this->assertSame('/about', $generator->to('about', [], false));
        $this->assertSame('/sub/articles/5', $generator->to('article', ['id' => 5]));
    }

    #[Test]
    public function it_generates_optional_segments(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/archive[/{year:\d+}]', ['App\\Controllers\\UnloadedController', 'show'])->name('archive');
        $generator = new RouteUrlGenerator($registry, new UrlGenerator('', '/'));

        $this->assertSame('/archive', $generator->to('archive'));
        $this->assertSame('/archive/2026', $generator->to('archive', ['year' => 2026]));
    }

    #[Test]
    public function it_rejects_bad_parameters(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/articles/{id:\d+}', ['App\\Controllers\\UnloadedController', 'show'])->name('article');
        $generator = new RouteUrlGenerator($registry, new UrlGenerator('', '/'));
        $caught = 0;

        foreach ([[], ['id' => 5, 'extra' => 1], ['id' => 'abc']] as $params) {
            try {
                $generator->to('article', $params);
            } catch (InvalidArgumentException) {
                $caught++;
            }
        }

        try {
            $generator->to('missing');
        } catch (InvalidArgumentException) {
            $caught++;
        }

        $this->assertSame(4, $caught);
    }

    #[Test]
    public function it_rejects_duplicate_names_without_compilation(): void
    {
        $registry = new RouteRegistry();
        $registry->get('/one', ['App\\Controllers\\UnloadedController', 'show'])->name('same');
        $registry->get('/two', ['App\\Controllers\\UnloadedController', 'show'])->name('same');
        $generator = new RouteUrlGenerator($registry, new UrlGenerator('', '/'));

        $this->expectException(LogicException::class);
        $generator->to('same');
    }

    #[Test]
    public function it_registers_named_routes_through_facade(): void
    {
        $container = new Container();
        $registry = new RouteRegistry();
        $container->instance(RouteRegistry::class, $registry);

        ContainerContext::run($container, static function (): void {
            Route::get('/about', ['App\\Controllers\\UnloadedController', 'show'])->name('about');
        });

        $this->assertTrue($registry->has('about'));
        $generator = new RouteUrlGenerator($registry, new UrlGenerator('', '/'));

        $this->assertSame('/about', $generator->to('about'));
    }

    #[Test]
    public function it_resolves_application_routes_from_root(): void
    {
        $root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-routes-' . bin2hex(random_bytes(8));
        mkdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config', 0755, true);

        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            "<?php declare(strict_types=1); return ['base_path' => '/sub'];"
        );
        file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php',
            <<<'PHP'
                <?php declare(strict_types=1);
                use Arix\Facades\Route;
                Route::get('/about', ['App\Controllers\AboutController', 'index'])->name('about');
                Route::get('/articles/{id:\d+}', ['App\Controllers\AboutController', 'index'])->name('article');
                PHP
        );

        try {
            $generator = RouteResolver::fromRoot($root);

            $this->assertSame('/sub/about', $generator->to('about'));
            $this->assertSame('/about', $generator->to('about', [], false));
            $this->assertSame('/sub/articles/7', $generator->to('article', ['id' => 7]));
        } finally {
            unlink($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php');
            unlink($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php');
            rmdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config');
            rmdir($root . DIRECTORY_SEPARATOR . 'app');
            rmdir($root);
        }
    }

    #[Test]
    public function it_exposes_route_helper_without_composer(): void
    {
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; ' . <<<'PHP'
            if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
            if (! function_exists('route')) { exit(3); }
            $container = new Arix\Container\Container;
            $registry = new Arix\Routing\RouteRegistry;
            $container->instance(Arix\Routing\RouteRegistry::class, $registry);
            $container->instance(Arix\Routing\UrlGenerator::class, new Arix\Routing\UrlGenerator('/sub', '/'));
            echo Arix\Container\ContainerContext::run($container, static function (): string {
                Arix\Facades\Route::get('/articles/{id:\d+}', ['App\Controllers\AboutController', 'index'])->name('article');
                return route('article', ['id' => 9]) . '|' . route('article', ['id' => 9], false);
            });
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('/sub/articles/9|/articles/9', $output);
    }
}
