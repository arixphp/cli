<?php

declare(strict_types=1);

namespace Arix\Http;

use Arix\Container\Container;
use Arix\Contracts\Middleware;
use RuntimeException;

class MiddlewareResolver
{
    public function resolve(string $middleware, Container $container): Middleware
    {
        if (! $container->has($middleware)) {
            throw new RuntimeException("Middleware is not registered: {$middleware}");
        }

        $resolved = $container->get($middleware);

        if (! $resolved instanceof Middleware) {
            throw new RuntimeException("Middleware binding returned an incompatible instance: {$middleware}");
        }

        return $resolved;
    }
}
