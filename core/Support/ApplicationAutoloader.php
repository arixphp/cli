<?php

declare(strict_types=1);

namespace Arix\Support;

use Arix\Autoload\AutoloadConfiguration;
use Arix\Autoload\ClassLoader;
use Closure;

// TODO: Drop
class ApplicationAutoloader
{
    public static function run(string $root, Closure $callback, bool|null $development = null): mixed
    {
        $previous = ClassLoader::current();
        $canonical = RuntimePaths::fromApplicationRoot($root)->root;
        $sameRoot = $previous?->configuration->root() === $canonical;
        $development ??= $sameRoot && $previous?->development === true;

        if (! $sameRoot || $previous->development !== $development) {
            $configuration = $sameRoot ? $previous->configuration : AutoloadConfiguration::read($canonical);
            (new ClassLoader($configuration, $development))->register();
        }

        try {

            return $callback();
        } finally {

            if (ClassLoader::current() !== $previous) {
                ClassLoader::current()?->unregister();
                $previous?->register();
            }
        }
    }
}
