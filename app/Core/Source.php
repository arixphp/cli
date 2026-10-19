<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

use function in_array;
use function is_array;
use function is_resource;
use function strlen;

class Source
{
    private const array PACKAGES = [
        'symfony/http-foundation' => ['HttpFoundation', 'Symfony\\Component\\HttpFoundation', 'Arix\\HttpFoundation', ''],
        'nikic/fast-route' => ['FastRoute', 'FastRoute', 'Arix\\FastRoute', 'src'],
        'eftec/bladeone' => ['BladeOne', 'eftec\\bladeone', 'Arix\\BladeOne', 'lib'],
        'dragonmantank/cron-expression' => ['Cron', 'Cron', 'Arix\\Cron', 'src/Cron'],
    ];

    public function import(string $vendor, string $core): array
    {
        $this->assertPackagesAvailable($vendor);

        $core = $this->prepareCore($core);
        $lock = $this->lockCore($core);
        $stage = $core . DIRECTORY_SEPARATOR . '.import-' . bin2hex(random_bytes(8));

        $installed = [];
        $backups = [];
        $completed = false;

        try {
            $manifest = $this->performImport($vendor, $core, $stage, $installed, $backups);

            $completed = true;

            return $manifest;
        } catch (Throwable $exception) {
            $this->rollback($core, $stage, $installed, $backups, $exception);

            throw $exception;
        } finally {
            $this->discardStage($stage, $completed);

            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Ensure every supported package is vendored, licensed and free of links. */
    private function assertPackagesAvailable(string $vendor): void
    {
        foreach (array_keys(self::PACKAGES) as $package) {
            $directory = $vendor . DIRECTORY_SEPARATOR . $package;
            $this->assertUnlinkedPath($directory);

            if (! $this->packageIsAvailable($directory, self::PACKAGES[$package][3])) {
                throw new RuntimeException("Missing package or license: {$package}");
            }
        }
    }

    private function packageIsAvailable(string $directory, string $sourceRoot): bool
    {
        if (! is_dir($directory) || ! is_file($directory . DIRECTORY_SEPARATOR . 'LICENSE')) {
            return false;
        }

        return $sourceRoot === '' || is_dir($directory . DIRECTORY_SEPARATOR . $sourceRoot);
    }

    /** Create the core directory when it is missing and resolve it to a real path. */
    private function prepareCore(string $core): string
    {
        $this->assertUnlinkedPath($core);

        if (! is_dir($core) && ! mkdir($core, 0755, true)) {
            throw new RuntimeException('Cannot create core directory.');
        }

        $resolved = realpath($core);

        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve core directory.');
        }

        return $resolved;
    }

    /**
     * Acquire the exclusive import lock of the core directory.
     *
     * @return resource
     */
    private function lockCore(string $core): mixed
    {
        $path = $core . DIRECTORY_SEPARATOR . '.import.lock';
        $this->assertUnlinkedPath($path);

        $lock = fopen($path, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Cannot lock core import.');
        }

        return $lock;
    }

    /**
     * Stage every supported package and install it over the managed destinations.
     *
     * @param  list<string>  $installed
     * @param  list<string>  $backups
     * @return array<string, mixed>
     */
    private function performImport(string $vendor, string $core, string $stage, array &$installed, array &$backups): array
    {
        $retired = $this->assertManaged($core);

        mkdir($stage, 0755);
        mkdir($stage . DIRECTORY_SEPARATOR . 'previous', 0755);

        $manifest = $this->stagePackages($vendor, $stage);

        $this->retireComponents($retired, $core, $stage, $backups);
        $this->installComponents($core, $stage, $installed, $backups);

        return $manifest;
    }

    /**
     * Copy every supported package into the staging area and record its manifest.
     *
     * @return array<string, mixed>
     */
    private function stagePackages(string $vendor, string $stage): array
    {
        $manifest = ['format' => 1, 'php' => '>=8.4.1', 'packages' => [], 'files' => []];
        $versions = $this->versions($vendor);

        foreach (self::PACKAGES as $package => [$destination, $from, $to, $sourceRoot]) {
            $root = $vendor . DIRECTORY_SEPARATOR . $package;
            $source = $sourceRoot === '' ? $root : $root . DIRECTORY_SEPARATOR . $sourceRoot;
            // The standalone core autoloader replaces FastRoute's bootstrap.
            $excluded = $package === 'nikic/fast-route' ? [$source . DIRECTORY_SEPARATOR . 'bootstrap.php'] : [];
            $this->copy($source, $stage . DIRECTORY_SEPARATOR . $destination, $from, $to, $excluded);
            $this->copy($root . DIRECTORY_SEPARATOR . 'LICENSE', $stage . DIRECTORY_SEPARATOR . $destination . DIRECTORY_SEPARATOR . 'LICENSE', null, null);

            $manifest['packages'][$package] = [
                'version' => $versions[$package] ?? 'unknown',
                'destination' => $destination,
                'namespace' => $to,
            ];

            $this->hashFiles($stage . DIRECTORY_SEPARATOR . $destination, $destination, $manifest['files']);
        }

        ksort($manifest['files']);

        $this->write($stage . DIRECTORY_SEPARATOR . 'imports.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return $manifest;
    }

    /**
     * Move historical imports out of the core directory.
     *
     * @param  list<string>  $retired
     * @param  list<string>  $backups
     */
    private function retireComponents(array $retired, string $core, string $stage, array &$backups): void
    {
        foreach ($retired as $name) {
            if (! $this->move($core . DIRECTORY_SEPARATOR . $name, $stage . DIRECTORY_SEPARATOR . 'previous' . DIRECTORY_SEPARATOR . $name)) {
                throw new RuntimeException("Cannot back up {$name}");
            }

            $backups[] = $name;
        }
    }

    /**
     * Replace every managed destination with its staged copy.
     *
     * @param  list<string>  $installed
     * @param  list<string>  $backups
     */
    private function installComponents(string $core, string $stage, array &$installed, array &$backups): void
    {
        foreach ([...$this->destinations(), 'imports.json'] as $name) {
            $this->backupComponent($core, $stage, $name, $backups);
            $this->installComponent($core, $stage, $name, $installed);
        }
    }

    /** @param list<string> $backups */
    private function backupComponent(string $core, string $stage, string $name, array &$backups): void
    {
        if (! file_exists($core . DIRECTORY_SEPARATOR . $name)) {
            return;
        }

        if (! $this->move($core . DIRECTORY_SEPARATOR . $name, $stage . DIRECTORY_SEPARATOR . 'previous' . DIRECTORY_SEPARATOR . $name)) {
            throw new RuntimeException("Cannot back up {$name}");
        }

        $backups[] = $name;
    }

    /** @param list<string> $installed */
    private function installComponent(string $core, string $stage, string $name, array &$installed): void
    {
        if (! $this->move($stage . DIRECTORY_SEPARATOR . $name, $core . DIRECTORY_SEPARATOR . $name)) {
            throw new RuntimeException("Cannot install {$name}");
        }

        $installed[] = $name;
    }

    /**
     * Restore the previous core contents after a failed import.
     *
     * @param  list<string>  $installed
     * @param  list<string>  $backups
     */
    private function rollback(string $core, string $stage, array $installed, array $backups, Throwable $exception): void
    {
        foreach (array_reverse($installed) as $name) {
            $this->remove($core . DIRECTORY_SEPARATOR . $name);
        }

        foreach ($backups as $name) {
            if (! $this->move($stage . DIRECTORY_SEPARATOR . 'previous' . DIRECTORY_SEPARATOR . $name, $core . DIRECTORY_SEPARATOR . $name)) {
                throw new RuntimeException("Import rollback failed; backup retained at {$stage}", previous: $exception);
            }
        }
    }

    /** Remove the staging area unless it still holds recovery evidence. */
    private function discardStage(string $stage, bool $completed): void
    {
        if (is_dir($stage) && (scandir($stage . DIRECTORY_SEPARATOR . 'previous') ?: []) === ['.', '..']) {
            $this->remove($stage);

            return;
        }

        if ($completed) {
            $this->remove($stage);
        }
    }

    /** @return list<string> Historical imported directories to retire after validation. */
    private function assertManaged(string $core): array
    {
        foreach ([...$this->destinations(), 'imports.json'] as $name) {
            $this->assertUnlinkedPath($core . DIRECTORY_SEPARATOR . $name);
        }

        if (! file_exists($core . DIRECTORY_SEPARATOR . 'imports.json')) {
            foreach ($this->destinations() as $name) {
                if (file_exists($core . DIRECTORY_SEPARATOR . $name)) {
                    throw new RuntimeException('Refusing to replace an unmanaged component.');
                }
            }

            return [];
        }

        $manifest = json_decode(file_get_contents($core . DIRECTORY_SEPARATOR . 'imports.json'), true, flags: JSON_THROW_ON_ERROR);

        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['files'] ?? null) || ! is_array($manifest['packages'] ?? null)) {
            throw new RuntimeException('Invalid import manifest.');
        }

        $actual = [];
        $managed = [];
        $retired = [];

        foreach ($manifest['packages'] as $package => $metadata) {
            if (! isset(self::PACKAGES[$package]) || ! is_array($metadata)) {
                throw new RuntimeException('Invalid managed package.');
            }

            $historical = $package === 'nikic/fast-route'
                && ($metadata['destination'] ?? null) === 'Routing'
                && ($metadata['namespace'] ?? null) === 'Arix\\Routing';

            if (! $historical && ($metadata['destination'] ?? null) !== self::PACKAGES[$package][0]) {
                throw new RuntimeException('Invalid managed package.');
            }

            if ($historical) {
                $retired[] = 'Routing';
            }

            $this->assertUnlinkedPath($core . DIRECTORY_SEPARATOR . $metadata['destination']);
            $managed[] = $metadata['destination'];
        }

        foreach ($this->destinations() as $name) {
            if (! in_array($name, $managed, true) && file_exists($core . DIRECTORY_SEPARATOR . $name)) {
                throw new RuntimeException("Refusing to replace unmanaged component: {$name}");
            }
        }

        foreach ($managed as $name) {
            if (! is_dir($core . DIRECTORY_SEPARATOR . $name)) {
                throw new RuntimeException("Managed component is missing: {$name}");
            }

            $this->hashFiles($core . DIRECTORY_SEPARATOR . $name, $name, $actual);
        }

        ksort($actual);

        if ($actual !== $manifest['files']) {
            throw new RuntimeException('Imported files have local changes; refusing to overwrite them.');
        }

        return $retired;
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

    private function copy(string $source, string $destination, null|string $from, null|string $to, array $excluded = []): void
    {
        if (is_link($source)) {
            throw new RuntimeException("Linked package source: {$source}");
        }

        if (basename($source) === 'bladeonecli' || in_array($source, $excluded, true)) {
            return;
        }

        if (is_dir($source)) {
            if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
                throw new RuntimeException("Cannot create {$destination}");
            }

            foreach (array_diff(scandir($source), ['.', '..']) as $entry) {
                $this->copy($source . DIRECTORY_SEPARATOR . $entry, $destination . DIRECTORY_SEPARATOR . $entry, $from, $to, $excluded);
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
            $content = $this->relocate($content, $from, $to);
        }

        $this->write($destination, $content);
    }

    /**
     * Rewrite a namespace token, but only where it is a namespace reference.
     *
     * A flat replacement is unsafe when a class name starts with the namespace
     * token, as "CronExpression" does for the "Cron" namespace.
     */
    private function relocate(string $content, string $from, string $to): string
    {
        $escaped = str_replace('\\', '\\\\', $from);

        if ($escaped !== $from) {
            $content = preg_replace_callback(
                '/' . preg_quote($escaped, '/') . '(?![A-Za-z0-9_])/',
                static fn (): string => str_replace('\\', '\\\\', $to),
                $content
            ) ?? $content;
        }

        return preg_replace_callback(
            '/' . preg_quote($from, '/') . '(?![A-Za-z0-9_])/',
            static fn (): string => $to,
            $content
        ) ?? $content;
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
                $this->hashFiles($path . DIRECTORY_SEPARATOR . $entry, $relative . DIRECTORY_SEPARATOR . $entry, $files);
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
        $file = $vendor . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'installed.json';

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
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }

            if (! rmdir($path)) {
                throw new RuntimeException("Cannot remove staging directory: {$path}");
            }
        } elseif (! unlink($path)) {
            throw new RuntimeException("Cannot remove staging file: {$path}");
        }
    }
}
