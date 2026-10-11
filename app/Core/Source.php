<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

use function count;
use function in_array;
use function is_array;
use function is_resource;
use function strlen;

class Source
{
    private const array PACKAGES = [
        'symfony/http-foundation' => ['HttpFoundation', 'Symfony\\Component\\HttpFoundation', 'Arix\\HttpFoundation', ''],
        'nikic/fast-route' => ['Routing', 'FastRoute', 'Arix\\Routing', 'src'],
    ];

    public function import(string $vendor, string $core): array
    {
        foreach (array_keys(self::PACKAGES) as $package) {
            $dirPackage = $vendor.DIRECTORY_SEPARATOR.$package;
            $this->assertUnlinkedPath($dirPackage);

            $sourceRoot = self::PACKAGES[$package][3];

            if (! is_dir($dirPackage) || ! is_file($dirPackage.DIRECTORY_SEPARATOR.'LICENSE') || ($sourceRoot !== '' && ! is_dir($dirPackage.DIRECTORY_SEPARATOR.$sourceRoot))) {
                throw new RuntimeException("Missing package or license: {$package}");
            }
        }

        $this->assertUnlinkedPath($core);

        if (! is_dir($core) && ! mkdir($core, 0755, true)) {
            throw new RuntimeException('Cannot create core directory.');
        }

        $core = realpath($core);
        $this->assertUnlinkedPath($core.DIRECTORY_SEPARATOR.'.import.lock');

        $lock = fopen($core.DIRECTORY_SEPARATOR.'.import.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Cannot lock core import.');
        }

        $stage = $core.DIRECTORY_SEPARATOR.'.import-'.bin2hex(random_bytes(8));
        $installed = [];
        $backups = [];

        try {
            $this->assertManaged($core);
            mkdir($stage, 0755);
            mkdir($stage.DIRECTORY_SEPARATOR.'previous', 0755);

            $manifest = ['format' => 1, 'php' => '>=8.4.1', 'packages' => [], 'files' => []];
            $versions = $this->versions($vendor);

            foreach (self::PACKAGES as $package => [$destination, $from, $to, $sourceRoot]) {
                $root = $vendor.DIRECTORY_SEPARATOR.$package;
                $source = $sourceRoot === '' ? $root : $root.DIRECTORY_SEPARATOR.$sourceRoot;
                $this->copy($source, $stage.DIRECTORY_SEPARATOR.$destination, $from, $to);
                $this->copy($root.DIRECTORY_SEPARATOR.'LICENSE', $stage.DIRECTORY_SEPARATOR.$destination.DIRECTORY_SEPARATOR.'LICENSE', null, null);

                $manifest['packages'][$package] = [
                    'version' => $versions[$package] ?? 'unknown',
                    'destination' => $destination,
                    'namespace' => $to,
                ];

                $this->hashFiles($stage.DIRECTORY_SEPARATOR.$destination, $destination, $manifest['files']);
            }

            ksort($manifest['files']);

            $this->write($stage.DIRECTORY_SEPARATOR.'imports.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

            foreach ([...$this->destinations(), 'imports.json'] as $name) {
                if (file_exists($core.DIRECTORY_SEPARATOR.$name)) {
                    if (! $this->move($core.DIRECTORY_SEPARATOR.$name, $stage.DIRECTORY_SEPARATOR.'previous'.DIRECTORY_SEPARATOR.$name)) {
                        throw new RuntimeException("Cannot back up {$name}");
                    }

                    $backups[] = $name;
                }

                if (! $this->move($stage.DIRECTORY_SEPARATOR.$name, $core.DIRECTORY_SEPARATOR.$name)) {
                    throw new RuntimeException("Cannot install {$name}");
                }

                $installed[] = $name;
            }

            return $manifest;
        } catch (Throwable $exception) {
            foreach (array_reverse($installed) as $name) {
                $this->remove($core.DIRECTORY_SEPARATOR.$name);
            }

            foreach ($backups as $name) {
                if (! $this->move($stage.DIRECTORY_SEPARATOR.'previous'.DIRECTORY_SEPARATOR.$name, $core.DIRECTORY_SEPARATOR.$name)) {
                    throw new RuntimeException("Import rollback failed; backup retained at {$stage}", previous: $exception);
                }
            }

            throw $exception;
        } finally {
            if (is_dir($stage) && (scandir($stage.DIRECTORY_SEPARATOR.'previous') ?: []) === ['.', '..']) {
                $this->remove($stage);
            } elseif (count($installed) === count($this->destinations()) + 1) {
                $this->remove($stage);
            }

            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function assertManaged(string $core): void
    {
        foreach ([...$this->destinations(), 'imports.json'] as $name) {
            $this->assertUnlinkedPath($core.DIRECTORY_SEPARATOR.$name);
        }

        if (! file_exists($core.DIRECTORY_SEPARATOR.'imports.json')) {
            foreach ($this->destinations() as $name) {
                if (file_exists($core.DIRECTORY_SEPARATOR.$name)) {
                    throw new RuntimeException('Refusing to replace an unmanaged component.');
                }
            }

            return;
        }

        $manifest = json_decode(file_get_contents($core.DIRECTORY_SEPARATOR.'imports.json'), true, flags: JSON_THROW_ON_ERROR);

        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null) || ! is_array($manifest['packages'] ?? null)) {
            throw new RuntimeException('Invalid import manifest.');
        }

        $actual = [];
        $managed = [];

        foreach ($manifest['packages'] as $package => $metadata) {
            if (! isset(self::PACKAGES[$package]) || ! is_array($metadata) || ($metadata['destination'] ?? null) !== self::PACKAGES[$package][0]) {
                throw new RuntimeException('Invalid managed package.');
            }

            $managed[] = $metadata['destination'];
        }

        foreach ($this->destinations() as $name) {
            if (! in_array($name, $managed, true) && file_exists($core.DIRECTORY_SEPARATOR.$name)) {
                throw new RuntimeException("Refusing to replace unmanaged component: {$name}");
            }
        }

        foreach ($managed as $name) {
            if (! is_dir($core.DIRECTORY_SEPARATOR.$name)) {
                throw new RuntimeException("Managed component is missing: {$name}");
            }

            $this->hashFiles($core.DIRECTORY_SEPARATOR.$name, $name, $actual);
        }

        ksort($actual);

        if ($actual !== $manifest['files']) {
            throw new RuntimeException('Imported files have local changes; refusing to overwrite them.');
        }
    }

    private function destinations(): array
    {
        return array_column(self::PACKAGES, 0);
    }

    protected function move(string $from, string $to): bool
    {
        return rename($from, $to);
    }

    private function assertUnlinkedPath(string $path): void
    {
        do {
            if (is_link($path)) {
                throw new RuntimeException("Symlinks are not supported: {$path}");
            }

            $parent = dirname($path);

            if ($parent === $path) {
                break;
            }

            $path = $parent;
        } while (true);
    }

    private function copy(string $source, string $destination, null|string $from, null|string $to): void
    {
        if (is_link($source)) {
            throw new RuntimeException("Linked package source: {$source}");
        }

        if (is_dir($source)) {
            if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
                throw new RuntimeException("Cannot create {$destination}");
            }

            foreach (array_diff(scandir($source), ['.', '..']) as $entry) {
                $this->copy($source.DIRECTORY_SEPARATOR.$entry, $destination.DIRECTORY_SEPARATOR.$entry, $from, $to);
            }

            return;
        }

        if (pathinfo($source, PATHINFO_EXTENSION) !== 'php' && basename($source) !== 'LICENSE') {
            return;
        }

        $content = file_get_contents($source);

        if ($content === false) {
            throw new RuntimeException("Cannot read {$source}");
        }

        if ($from !== null && str_ends_with($source, '.php')) {
            $content = str_replace([$from, str_replace('\\', '\\\\', $from)], [$to, str_replace('\\', '\\\\', $to)], $content);
        }

        $this->write($destination, $content);
    }

    private function write(string $file, string $content): void
    {
        if (file_put_contents($file, $content) !== strlen($content)) {
            throw new RuntimeException("Cannot write {$file}");
        }
    }

    private function hashFiles(string $path, string $relative, array &$files): void
    {
        if (is_link($path)) {
            throw new RuntimeException("Linked imported file: {$path}");
        }

        if (is_dir($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->hashFiles($path.DIRECTORY_SEPARATOR.$entry, $relative.DIRECTORY_SEPARATOR.$entry, $files);
            }
        } else {
            $hash = hash_file('sha256', $path);

            if ($hash === false) {
                throw new RuntimeException("Cannot hash {$path}");
            }

            $files[$relative] = $hash;
        }
    }

    private function versions(string $vendor): array
    {
        $file = $vendor.DIRECTORY_SEPARATOR.'composer'.DIRECTORY_SEPARATOR.'installed.json';

        if (! is_file($file)) {
            return [];
        }

        $installed = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $versions = [];

        foreach ($installed['packages'] ?? $installed as $package) {
            $versions[$package['name']] = $package['version'];
        }

        return $versions;
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path.DIRECTORY_SEPARATOR.$entry);
            }

            if (! rmdir($path)) {
                throw new RuntimeException("Cannot remove staging directory: {$path}");
            }
        } elseif (! unlink($path)) {
            throw new RuntimeException("Cannot remove staging file: {$path}");
        }
    }
}
