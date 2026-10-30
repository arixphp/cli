<?php

declare(strict_types=1);

namespace App\Middleware;

use Arix\Container\ContainerContext;
use Arix\Contracts\Middleware;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;
use stdClass;

class Trace implements Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $container = ContainerContext::current();

        if (! $container->has(stdClass::class)) {
            $container->instance(stdClass::class, (object) ['requests' => 0]);
        }

        $state = $container->get(stdClass::class);
        $state->requests++;
        $response = $next($request);
        $response->headers->set('X-Fixture-Request', (string) $state->requests);

        return $response;
    }
}
