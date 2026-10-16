<?php

declare(strict_types=1);

namespace App\Development;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class DevelopmentTarget
{
    public static function resolve(string|null $path, string $cwd): string
    {
        $root = self::file($path ?? $cwd, $cwd, true);

        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php'] as $required) {
            self::file(str_replace('/', DIRECTORY_SEPARATOR, $required), $root);
        }

        self::assertUnlinked($root . DIRECTORY_SEPARATOR . 'robots.txt');

        foreach (['styles', 'scripts', 'assets'] as $public) {
            $directory = $root . DIRECTORY_SEPARATOR . $public;
            self::assertUnlinked($directory);

            if (! is_dir($directory)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if ($entry->isLink()) {
                    throw new RuntimeException("Public assets must not contain links: {$entry->getPathname()}");
                }
            }
        }

        return $root;
    }

    public static function file(string $path, string $cwd, bool $directory = false): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Path must not be empty or contain null bytes.');
        }
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
        $path = $absolute ? $path : $cwd . DIRECTORY_SEPARATOR . $path;
        self::assertUnlinked($path);
        $real = realpath($path);

        if ($real === false || ! is_readable($real) || ($directory ? ! is_dir($real) : ! is_file($real))) {
            throw new RuntimeException('Missing readable ' . ($directory ? 'directory' : 'file') . ": {$path}");
        }

        return $real;
    }

    public static function assertUnlinked(string $path): void
    {
        while (true) {
            if (is_link($path)) {
                throw new RuntimeException("Linked paths are not supported: {$path}");
            }

            $parent = dirname($path);

            if ($parent === $path) {

                return;
            }

            $path = $parent;
        }
    }
}
