<?php

declare(strict_types=1);

namespace App\Testing;

use Arix\Autoload\AutoloadConfiguration;
use RuntimeException;

class ApplicationTarget
{
    public static function resolve(string|null $path, string $cwd): string
    {
        $path ??= $cwd;
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        $path = $absolute ? $path : $cwd . DIRECTORY_SEPARATOR . $path;
        self::assertUnlinked($path);
        $root = realpath($path);

        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Application directory does not exist.');
        }

        AutoloadConfiguration::read($root);

        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php', 'core/Autoload/AutoloadConfiguration.php', 'core/Autoload/ClassLoader.php', 'phpunit.xml', 'tests/autoload.php'] as $required) {
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required);
            self::assertUnlinked($file);

            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException("Application is missing readable {$required}.");
            }
        }

        return $root;
    }

    private static function assertUnlinked(string $path): void
    {
        while (true) {
            if (is_link($path)) {
                throw new RuntimeException("Application path is linked: {$path}");
            }

            $parent = dirname($path);

            if ($parent === $path) {

                return;
            }

            $path = $parent;
        }
    }
}
