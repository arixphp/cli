<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\CircularServiceException;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Container\ContainerRuntimeException;
use Arix\Container\InvalidServiceException;
use Arix\Container\UnknownServiceException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

class ContainerTest extends TestCase
{
    #[Test]
    public function it_explicit_bindings_singletons_and_replacement(): void
    {
        $container = new Container;
        $container->bind('transient', static fn (): stdClass => new stdClass);
        $this->assertNotSame($container->get('transient'), $container->get('transient'));
        $container->singleton('shared', static fn (): stdClass => new stdClass);
        $shared = $container->get('shared');
        $this->assertSame($shared, $container->get('shared'));
        $replacement = new stdClass;
        $container->instance('shared', $replacement);
        $this->assertSame($replacement, $container->get('shared'));
        $container->bind('shared', static fn (): stdClass => new stdClass);
        $this->assertNotSame($replacement, $container->get('shared'));
    }

    #[Test]
    public function it_unknown_services_are_not_autowired(): void
    {
        $this->expectException(UnknownServiceException::class);
        (new Container)->get(stdClass::class);
    }

    #[Test]
    public function it_circular_resolution_is_detected_and_stack_recovers(): void
    {
        $container = new Container;
        $container->bind('a', static fn (Container $c): object => $c->get('b'));
        $container->bind('b', static fn (Container $c): object => $c->get('a'));

        try {
            $container->get('a');
            $this->fail('Expected circular resolution to fail.');
        } catch (CircularServiceException) {
            $container->instance('b', new stdClass);
            $this->assertSame($container->get('b'), $container->get('a'));
        }
    }

    #[Test]
    public function it_failed_singletons_can_be_retried(): void
    {
        $attempts = 0;
        $container = new Container;
        $container->singleton('retry', static function () use (&$attempts): stdClass {
            if (++$attempts === 1) {
                throw new RuntimeException('First attempt failed.');
            }

            return new stdClass;
        });

        try {
            $container->get('retry');
            $this->fail('First attempt must fail.');
        } catch (RuntimeException) {
            $this->assertSame($container->get('retry'), $container->get('retry'));
            $this->assertSame(2, $attempts);
        }
    }

    #[Test]
    public function it_factories_must_return_objects(): void
    {
        $container = new Container;
        $container->bind('invalid', static fn (): string => 'not an object');
        $this->expectException(InvalidServiceException::class);
        $container->get('invalid');
    }

    #[Test]
    public function it_resolving_services_cannot_replace_themselves(): void
    {
        $container = new Container;
        $container->bind('a', static function (Container $c): stdClass {
            $c->instance('a', new stdClass);

            return new stdClass;
        });
        $this->expectException(ContainerRuntimeException::class);
        $container->get('a');
    }

    #[Test]
    public function it_context_restores_nested_scope_after_exceptions(): void
    {
        $outer = new Container;
        $inner = new Container;
        ContainerContext::run($outer, function () use ($outer, $inner): void {
            try {
                ContainerContext::run($inner, function () use ($inner): void {
                    $this->assertSame($inner, ContainerContext::current());
                    throw new RuntimeException('Nested error.');
                });
            } catch (RuntimeException) {
                $this->assertSame($outer, ContainerContext::current());
            }
        });
        $this->expectException(LogicException::class);
        ContainerContext::current();
    }
}
