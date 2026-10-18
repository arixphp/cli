<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Core\Update\CoreFiles;
use RuntimeException;

use function in_array;
use function is_string;

class FrontendFiles
{
    public static function application(string $root): string
    {
        CoreFiles::assertUnlinked($root);
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Application directory is missing.');
        }

        foreach (['index.php', 'app/bootstrap.php'] as $required) {
            $file = CoreFiles::local($real, $required);
            CoreFiles::assertUnlinked($file);

            if (! is_file($file)) {
                throw new RuntimeException('Application entry point or bootstrap is missing.');
            }
        }

        return $real;
    }

    public static function target(string $application, string $directory): string
    {

        if (! in_array($directory, ['scripts/arix', 'styles/arix'], true)) {
            throw new RuntimeException('Invalid managed frontend directory.');
        }

        $path = $application;

        foreach (explode('/', $directory) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;
            CoreFiles::assertUnlinked($path);
            clearstatcache(true, $path);

            if (file_exists($path) && (! is_dir($path) || realpath($path) !== $path || ! in_array($segment, scandir(dirname($path)) ?: [], true))) {
                throw new RuntimeException('Invalid managed frontend directory or casing.');
            }
        }

        return $path;
    }

    /** @param list<string> $allowed
     * @return array<string, string>|null */
    public static function inventory(string $application, string $directory, array $allowed): array|null
    {
        $target = self::target($application, $directory);

        if (! is_dir($target)) {

            return null;
        }

        $inventory = [];

        foreach (scandir($target) ?: [] as $file) {

            if ($file === '.' || $file === '..') {
                continue;
            }

            if (! in_array($file, $allowed, true)) {
                throw new RuntimeException('Managed frontend directory contains unmanaged files.');
            }

            $path = $target . DIRECTORY_SEPARATOR . $file;
            CoreFiles::assertUnlinked($path);

            if (! is_file($path) || realpath($path) !== $path) {
                throw new RuntimeException('Invalid managed frontend file.');
            }

            $hash = hash_file('sha256', $path);

            if (! is_string($hash)) {
                throw new RuntimeException('Cannot hash managed frontend file.');
            }

            $inventory[$file] = $hash;
        }

        ksort($inventory);

        return $inventory;
    }

    public static function assertNoPendingOperation(string $application): void
    {

        foreach (scandir($application) ?: [] as $entry) {

            if ($entry === '.frontend-update.json' || str_starts_with($entry, '.frontend-stage-')) {
                throw new RuntimeException('Pending frontend operation requires manual recovery; retain its journal and staging files.');
            }
        }
    }
}
