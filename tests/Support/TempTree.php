<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

class TempTree
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-assets-' . bin2hex(random_bytes(8));
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

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function applicationManifest(): void
    {
        $this->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/","Arix\\\\":"core/"}}}');
    }
}
