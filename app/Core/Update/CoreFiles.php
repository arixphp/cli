<?php

declare(strict_types=1);

namespace App\Core\Update;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function is_string;
use function strlen;

class CoreFiles
{
    public static function assertUnlinked(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new RuntimeException('Invalid local filesystem path.');
        }

        $absolute = str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
        $path = $absolute ? $path : getcwd() . DIRECTORY_SEPARATOR . $path;
        $current = '';

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $part) {
            $current .= $part . DIRECTORY_SEPARATOR;

            if (is_link(rtrim($current, DIRECTORY_SEPARATOR))) {
                throw new RuntimeException("Refusing symbolic link: {$current}");
            }
        }
    }

    public static function assertRelative(string $path): void
    {
        if ($path !== '.htaccess' && ! preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_][A-Za-z0-9_.-]*)*$/D', $path)) {
            throw new RuntimeException("Unsafe manifest path: {$path}");
        }
    }

    public static function local(string $root, string $relative): string
    {
        self::assertRelative($relative);

        return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** @return array<string, string> */
    public static function inventory(string $root, bool $source = false, bool $strictDirectories = false): array
    {
        self::assertUnlinked($root);

        if (! is_dir($root)) {
            throw new RuntimeException("Missing core directory: {$root}");
        }

        $files = [];
        $directories = [];
        $seen = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));

            if ($entry->isLink()) {
                throw new RuntimeException("Refusing symbolic link: {$relative}");
            }

            if ($source && str_starts_with($relative, '.import-')) {
                throw new RuntimeException('Unfinished import state must be recovered before packaging.');
            }

            if ($source && ($relative === '.import.lock' || $entry->getFilename() === '.DS_Store' || $entry->getFilename() === 'README.md')) {
                if (! $entry->isFile()) {
                    throw new RuntimeException("Invalid development artifact: {$relative}");
                }

                continue;
            }

            self::assertRelative($relative);
            $folded = strtolower($relative);

            if (isset($seen[$folded])) {
                throw new RuntimeException("Case-colliding core path: {$relative}");
            }

            $seen[$folded] = true;

            if (($relative === '.htaccess' || $relative === 'stubs/error.html') && ! $entry->isFile()) {
                throw new RuntimeException("Unsupported runtime resource: {$relative}");
            }

            if ($entry->isDir()) {
                $directories[] = $relative;

                continue;
            }

            if (! $entry->isFile()) {
                throw new RuntimeException("Unsupported core file: {$relative}");
            }

            if ($source && ! str_ends_with($relative, '.php') && $entry->getFilename() !== 'LICENSE'
                && $relative !== 'imports.json' && $relative !== '.htaccess' && $relative !== 'stubs/error.html'
                && $relative !== 'Assets/public-policy.json') {
                throw new RuntimeException("Unsupported distribution file: {$relative}");
            }

            if (! $source && $relative === 'distribution.json') {
                continue;
            }

            $hash = hash_file('sha256', $path);

            if (! is_string($hash)) {
                throw new RuntimeException("Cannot hash core file: {$relative}");
            }

            $files[$relative] = $hash;
        }

        ksort($files);

        if ($strictDirectories) {
            $managedDirectories = [];

            foreach (array_keys($files) as $file) {
                $parts = explode('/', $file);
                array_pop($parts);

                while ($parts !== []) {
                    $managedDirectories[implode('/', $parts)] = true;
                    array_pop($parts);
                }
            }

            foreach ($directories as $directory) {
                if (! isset($managedDirectories[$directory])) {
                    throw new RuntimeException("Installed core has local changes; unmanaged directory: {$directory}");
                }
            }
        }

        return $files;
    }

    public static function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Cannot read file: {$path}");
        }

        return $contents;
    }

    public static function write(string $path, string $contents): void
    {
        self::assertUnlinked($path);

        if ((file_exists($path) && ! is_file($path))
            || @file_put_contents($path, $contents) !== strlen($contents) || ! @chmod($path, 0644)) {
            throw new RuntimeException("Cannot write file: {$path}");
        }
    }
}
