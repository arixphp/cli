<?php

declare(strict_types=1);

namespace App\Development;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function is_array;
use function is_string;
use function strlen;

class RuntimeStore
{
    public function __construct(
        private readonly RuntimeManifest $manifest,
        private readonly RuntimeDownloader $downloader,
        private readonly RuntimeArchive $archive,
        private readonly RuntimeProbe $probe,
        private readonly string|null $cache = null,
    ) {}

    public function resolve(string|null $override, bool $reload = true, callable|null $progress = null): string
    {
        if ($override !== null) {
            $this->probe->verify($override, $reload);

            return $override;
        }

        $platform = RuntimePlatform::detect();
        $target = $this->manifest->target($platform);
        $cache = $this->cache ?? $this->userCache();

        DevelopmentTarget::assertUnlinked($cache);

        if (! is_dir($cache) && ! @mkdir($cache, 0700, true)) {
            throw new RuntimeException('Cannot create user-private FrankenPHP cache.');
        }

        $directory = $cache . DIRECTORY_SEPARATOR . $this->manifest->version . '-' . $platform;

        DevelopmentTarget::assertUnlinked($directory . '.lock');

        $lock = @fopen($directory . '.lock', 'c+b');

        if ($lock === false) {
            throw new RuntimeException('Cannot open FrankenPHP install lock.');
        }

        $staging = null;

        try {
            $deadline = microtime(true) + 120;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Timed out waiting for another FrankenPHP installer.');
                }

                usleep(100000);
            }

            DevelopmentTarget::assertUnlinked($directory);

            $executable = $directory . DIRECTORY_SEPARATOR . $target['executable'];

            if (is_dir($directory)) {
                $this->verifyInstalled($directory, $target);
                $this->probe->verify($executable, $reload);

                return $executable;
            }

            if ($progress !== null) {
                $progress('Downloading pinned FrankenPHP ' . $this->manifest->version . " ({$platform}); subsequent starts work offline.");
            }

            $staging = new SessionFiles($cache);
            $download = $staging->directory . DIRECTORY_SEPARATOR . 'download';

            $this->downloader->download($target, $download);

            $install = $staging->directory . DIRECTORY_SEPARATOR . 'runtime';

            mkdir($install, 0700);

            if ($target['archive']) {
                $this->archive->extract($download, $install, $target);
            } elseif (! rename($download, $install . DIRECTORY_SEPARATOR . $target['executable'])) {
                throw new RuntimeException('Cannot stage FrankenPHP executable.');
            }

            $candidate = $install . DIRECTORY_SEPARATOR . $target['executable'];

            if (PHP_OS_FAMILY !== 'Windows' && ! @chmod($candidate, 0700)) {
                throw new RuntimeException('Cannot mark FrankenPHP executable.');
            }

            $files = $this->hashes($install);
            $this->probe->verify($candidate, $reload);

            file_put_contents($install . DIRECTORY_SEPARATOR . 'installed.json', json_encode(['artifact' => $target['sha256'], 'files' => $files], JSON_THROW_ON_ERROR));

            if (! rename($install, $directory)) {
                throw new RuntimeException('Cannot publish verified FrankenPHP installation.');
            }

            return $executable;
        } finally {
            $staging?->cleanup();
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function verifyInstalled(string $directory, array $target): void
    {
        DevelopmentTarget::assertUnlinked($directory . DIRECTORY_SEPARATOR . 'installed.json');

        $contents = @file_get_contents($directory . DIRECTORY_SEPARATOR . 'installed.json');
        $manifest = $contents === false ? null : json_decode($contents, true);

        if (! is_array($manifest) || ($manifest['artifact'] ?? '') !== $target['sha256'] || ($manifest['files'] ?? null) !== $this->hashes($directory)) {
            throw new RuntimeException("FrankenPHP cache integrity failed at {$directory}. Inspect/remove this specific installation manually or use --frankenphp.");
        }

        if (! $target['archive'] && ($manifest['files'][$target['executable']] ?? '') !== $target['sha256']) {
            throw new RuntimeException('FrankenPHP executable integrity failed.');
        }
    }

    private function hashes(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $entry) {
            if ($entry->isLink() || (! $entry->isDir() && ! $entry->isFile())) {
                throw new RuntimeException('FrankenPHP cache integrity failed: linked or special entry.');
            }

            if ($entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen($directory) + 1);

                if ($relative === 'installed.json') {
                    continue;
                }

                $hash = hash_file('sha256', $entry->getPathname());

                if ($hash === false) {
                    throw new RuntimeException('Cannot inspect FrankenPHP cache integrity.');
                }

                $files[$relative] = $hash;
            }
        }

        ksort($files);

        return $files;
    }

    private function userCache(): string
    {
        $home = getenv('HOME');
        $local = getenv('LOCALAPPDATA');
        $xdg = getenv('XDG_CACHE_HOME');
        $path = match (PHP_OS_FAMILY) {
            'Windows' => $local ? $local . DIRECTORY_SEPARATOR . 'Arix' . DIRECTORY_SEPARATOR . 'Cache' : null,
            'Darwin' => $home ? $home . DIRECTORY_SEPARATOR . 'Library' . DIRECTORY_SEPARATOR . 'Caches' . DIRECTORY_SEPARATOR . 'arix' : null,
            default => $xdg ?: ($home ? $home . DIRECTORY_SEPARATOR . '.cache' : null),
        };
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Cannot determine user cache directory; use --frankenphp.');
        }

        return PHP_OS_FAMILY === 'Linux' ? $path . DIRECTORY_SEPARATOR . 'arix' : $path;
    }
}
