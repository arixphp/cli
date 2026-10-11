<?php

declare(strict_types=1);

namespace Arix\Facades;

use Arix\Container\ContainerContext;
use Arix\Constants\HttpMethod;
use Arix\Http\Routing\RouteDefinition;
use Arix\Http\Routing\RouteRegistry;

class Route
{
    public static function get(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::GET->value], $path, $handler);
    }

    public static function head(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::HEAD->value], $path, $handler);
    }

    public static function post(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::POST->value], $path, $handler);
    }

    public static function put(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::PUT->value], $path, $handler);
    }

    public static function patch(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::PATCH->value], $path, $handler);
    }

    public static function delete(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::DELETE->value], $path, $handler);
    }

    public static function options(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::OPTIONS->value], $path, $handler);
    }

    public static function query(string $path, array $handler): RouteDefinition
    {
        return self::match([HttpMethod::QUERY->value], $path, $handler);
    }

    public static function match(array $methods, string $path, array $handler): RouteDefinition
    {
        return ContainerContext::current()->get(RouteRegistry::class)->match($methods, $path, $handler);
    }
}
