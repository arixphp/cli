<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Http\MiddlewareConfiguration;
use Arix\Http\MiddlewareDispatcher;
use Arix\Container\Container;
use Arix\Contracts\Middleware;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypeError;

class MiddlewareTest extends TestCase
{
    #[Test]
    public function it_dispatches_middleware_in_declaration_order(): void
    {
        $events = [];
        $first = new class($events) implements Middleware { public function __construct(private array &$events) {} public function handle(Request $request, Closure $next): Response { $this->events[] = 'first-in'; $response = $next($request); $this->events[] = 'first-out'; return $response; } };
        $second = new class($events) implements Middleware { public function __construct(private array &$events) {} public function handle(Request $request, Closure $next): Response { $this->events[] = 'second-in'; $response = $next($request); $this->events[] = 'second-out'; return $response; } };
        $container = new Container();
        $container->instance('first', $first);
        $container->instance('second', $second);

        $response = (new MiddlewareDispatcher())->dispatch(Request::create('/'), $container, ['first', 'second'], static function (Request $request) use (&$events): Response { $events[] = 'terminal'; return new Response('ok'); });

        $this->assertSame('ok', $response->getContent());
        $this->assertSame(['first-in', 'second-in', 'terminal', 'second-out', 'first-out'], $events);
    }
    #[Test]
    public function it_keeps_global_middleware_in_declaration_order(): void
    {
        $middleware = new MiddlewareConfiguration();
        $middleware->append('App\\Middleware\\First');
        $middleware->append(['App\\Middleware\\Second', 'App\\Middleware\\Third']);

        $this->assertSame([
            'App\\Middleware\\First',
            'App\\Middleware\\Second',
            'App\\Middleware\\Third',
        ], $middleware->all());
    }

    #[Test]
    public function it_rejects_invalid_global_middleware_declarations(): void
    {
        foreach (['', [], ['App\\Middleware\\Valid', ''], ['App\\Middleware\\Valid' => 'App\\Middleware\\Other']] as $declaration) {
            $middleware = new MiddlewareConfiguration();

            try {
                $middleware->append($declaration);
                $this->fail('Invalid middleware declarations must be rejected.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(TypeError::class);
        (new MiddlewareConfiguration())->append(1);

    }
}
