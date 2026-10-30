<?php

declare(strict_types=1);

namespace App\Core\Update;

use Arix\Autoload\AutoloadConfiguration;
use RuntimeException;

class ApplicationTarget
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

        $metadata = $application . DIRECTORY_SEPARATOR . '.arix.json';
        CoreFiles::assertUnlinked($metadata);

        if (! is_file($metadata)) {
            throw new RuntimeException('Application compatibility metadata is missing; migrate the application first.');
        }

        AutoloadConfiguration::read($application);

        foreach (['index.php', 'app/bootstrap.php'] as $required) {
            $file = CoreFiles::local($application, $required);
            CoreFiles::assertUnlinked($file);

            if (! is_file($file)) {
                throw new RuntimeException("Application is missing {$required}.");
            }
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
