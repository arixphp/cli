<?php

declare(strict_types=1);

/*
 * trigger_deprecation() is copied from symfony/deprecation-contracts.
 *
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

if (! function_exists('trigger_deprecation')) {
    function trigger_deprecation(string $package, string $version, string $message, mixed ...$args): void
    {
        @trigger_error(($package || $version ? "Since $package $version: " : '').($args ? vsprintf($message, $args) : $message), \E_USER_DEPRECATED);
    }
}

if (! function_exists('view')) {
    function view(string $name, array $data = []): Arix\Views\Contracts\View
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Views\ViewFactory::class)->make($name, $data);
    }
}

if (! function_exists('trans')) {
    function trans(string $key, array $replace = []): string
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Localization\Translator::class)->get($key, $replace);
    }
}

if (! function_exists('url')) {
    function url(null|string $path = null): Arix\Routing\UrlGenerator|string
    {
        $generator = Arix\Container\ContainerContext::current()->get(Arix\Routing\UrlGenerator::class);

        return $path === null ? $generator : $generator->to($path);
    }
}

if (! function_exists('config')) {
    function config(null|string $key = null, mixed $default = null): mixed
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Config\Repository::class)->get($key, $default);
    }
}

if (! function_exists('asset')) {
    function asset(string $path): string
    {
        return Arix\Container\ContainerContext::current()->get(Arix\Routing\UrlGenerator::class)->asset($path);
    }
}

