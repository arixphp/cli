<?php

declare(strict_types=1);

namespace App\Css\Update;

use App\Core\Update\CoreFiles;
use RuntimeException;

class CssTarget
{
    public static function resolve(string|null $path, string $cwd, string|null $source = null): string
    {
        $path ??= $cwd;
        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        $path = $absolute ? $path : $cwd . DIRECTORY_SEPARATOR . $path;
        CoreFiles::assertUnlinked($path);
        $application = realpath($path);

        if ($application === false || ! is_dir($application)) {
            throw new RuntimeException('Application directory does not exist.');
        }

        if ($source !== null) {
            CoreFiles::assertUnlinked($source);
            $source = realpath($source);

            if ($source === false || self::contains($application, $source) || self::contains($source, $application)) {
                throw new RuntimeException('Application and distribution source overlap.');
            }
        }

        foreach (['index.php', 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php'] as $required) {
            $file = $application . DIRECTORY_SEPARATOR . $required;
            CoreFiles::assertUnlinked($file);

            if (! is_file($file)) {
                throw new RuntimeException("Application is missing {$required}.");
            }
        }

        $styles = $application . DIRECTORY_SEPARATOR . 'styles';
        CoreFiles::assertUnlinked($styles);

        if (! is_dir($styles)) {
            throw new RuntimeException('Application styles directory is missing.');
        }

        return $application;
    }

    private static function contains(string $parent, string $child): bool
    {
        $parent = rtrim($parent, DIRECTORY_SEPARATOR);
        $child = rtrim($child, DIRECTORY_SEPARATOR);

        if (DIRECTORY_SEPARATOR === '\\') {
            $parent = strtolower($parent);
            $child = strtolower($child);
        }

        return $parent === $child || str_starts_with($child, $parent . DIRECTORY_SEPARATOR);
    }
}
