<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class JsUpdateFixture
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-js-' . bin2hex(random_bytes(8));
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

    public function framework(string $version, string $suffix = ''): string
    {

        return "// Arix UI {$version}{$suffix}\nexport const version = '{$version}';\nexport const marker = '{$version}{$suffix}';\n";
    }

    /** @param array<string, string> $releases */
    public function distribution(string $relative, string $version, string $body, array $releases = []): string
    {
        $this->write("{$relative}/resources/js/arix.js", $body);
        $hashes = array_replace($releases, [$version => hash('sha256', $body)]);
        ksort($hashes);
        $this->write("{$relative}/resources/js/manifest.json", json_encode(['format' => 1, 'releases' => $hashes], JSON_THROW_ON_ERROR));

        return $this->path($relative);
    }

    public function application(string $relative = 'application', string|null $installedVersion = '1.0.0'): string
    {
        $this->write("{$relative}/index.php", '<?php // application entry point');
        $this->write("{$relative}/app/bootstrap.php", '<?php // application bootstrap');

        if ($installedVersion !== null) {
            $this->write("{$relative}/scripts/arix.js", $this->framework($installedVersion));
        } elseif (! is_dir($this->path("{$relative}/scripts"))) {
            mkdir($this->path("{$relative}/scripts"), 0700, true);
        }

        $this->write("{$relative}/scripts/app.js", '// application entry');

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
