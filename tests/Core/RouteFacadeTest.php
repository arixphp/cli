<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Facades\Route;
use Arix\Routing\RouteRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RouteFacadeTest extends TestCase
{
    #[Test]
    public function it_registers_only_in_the_active_container(): void
    {
        foreach (['/one', '/two'] as $path) {
            $container = new Container;
            $registry = new RouteRegistry;
            $container->instance(RouteRegistry::class, $registry);
            ContainerContext::run($container, static function () use ($path): void {
                Route::get($path, ['App\\Controllers\\UnloadedController', 'show']);
            });
            $this->assertSame(1, $registry->dispatcher()->dispatch('GET', $path)[0]);
            $this->assertSame(0, $registry->dispatcher()->dispatch('GET', '/unknown')[0]);
        }
    }

    #[Test]
    public function it_requires_an_active_container(): void
    {
        $this->expectException(LogicException::class);
        Route::get('/', ['App\\Controllers\\UnloadedController', 'show']);
    }
}
