<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class CssUpdateFixture
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-css-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    public function path(string $relative): string
    {

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function write(string $relative, string $contents): string
    {
        $path = $this->path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    public function stylesheet(string $file, string $version, string $suffix = ''): string
    {

        return "/* Arix CSS {$file} {$version}{$suffix} */\n.layer-{$version} { color: red; }\n";
    }

    /** @return array<string, string> */
    public function versionSet(string $version, string $suffix = ''): array
    {
        $bodies = [];

        foreach (['base.css', 'components.css', 'tokens.css', 'utilities.css'] as $file) {
            $bodies[$file] = $this->stylesheet($file, $version, $suffix);
        }

        return $bodies;
    }

    /** @param array<string, string> $bodies
     * @return array<string, string> */
    public function hashes(array $bodies): array
    {
        $hashes = [];

        foreach ($bodies as $file => $body) {
            $hashes[$file] = hash('sha256', $body);
        }

        return $hashes;
    }

    /** @param array<string, string> $bodies
     * @param array<string, array<string, string>> $releases */
    public function distribution(string $relative, string $version, array $bodies, array $releases = []): string
    {
        foreach ($bodies as $file => $body) {
            $this->write("{$relative}/resources/css/{$file}", $body);
        }

        $recorded = array_replace($releases, [$version => $this->hashes($bodies)]);
        ksort($recorded);
        $this->write("{$relative}/resources/css/manifest.json", json_encode(['format' => 1, 'releases' => $recorded], JSON_THROW_ON_ERROR));

        return $this->path($relative);
    }

    public function application(string $relative = 'application', string|null $installedVersion = '1.0.0'): string
    {
        $this->write("{$relative}/index.php", '<?php // application entry point');
        $this->write("{$relative}/app/bootstrap.php", '<?php // application bootstrap');

        if ($installedVersion !== null) {
            foreach ($this->versionSet($installedVersion) as $file => $body) {
                $this->write("{$relative}/styles/{$file}", $body);
            }
        } elseif (! is_dir($this->path("{$relative}/styles"))) {
            mkdir($this->path("{$relative}/styles"), 0700, true);
        }

        $this->write("{$relative}/styles/app.css", '/* application entry */');

        return $this->path($relative);
    }

    public function cleanup(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($this->root);
    }
}
