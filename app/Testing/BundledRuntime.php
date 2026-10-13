<?php

declare(strict_types=1);

namespace App\Testing;

use Phar;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

use function strlen;

class BundledRuntime
{
    public static function prepare(string $distribution): string
    {
        if (! str_starts_with($distribution, 'phar://')) {

            return $distribution;
        }

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-test-runtime-' . bin2hex(random_bytes(16));

        if (! mkdir($root, 0700)) {
            throw new RuntimeException('Cannot create private test runtime.');
        }

        // Keep dependencies available to Pest and application shutdown callbacks.
        // Append cleanup after callbacks registered later during test execution.
        $cleanup = static function () use ($root): void {
            if (! is_dir($root)) {

                return;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($iterator as $file) {
                if ($file->isDir() && ! $file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }

            rmdir($root);
        };
        register_shutdown_function(static function () use ($cleanup): void {
            register_shutdown_function($cleanup);
        });

        $archive = new Phar(Phar::running(false));
        $prefix = 'phar://' . $archive->getPath() . '/';

        foreach (new RecursiveIteratorIterator($archive) as $file) {
            $relative = substr($file->getPathname(), strlen($prefix));

            if (! str_starts_with($relative, 'vendor/')) {
                continue;
            }

            if ($file->isLink() || preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00]~', $relative)) {
                throw new RuntimeException('Unsafe bundled test dependency path.');
            }

            $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (! is_dir(dirname($destination)) && ! mkdir(dirname($destination), 0700, true)) {
                throw new RuntimeException('Cannot create test dependency directory.');
            }

            if (! copy($file->getPathname(), $destination)) {
                throw new RuntimeException('Cannot extract bundled test dependency.');
            }
        }

        if (! is_file($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php')) {
            throw new RuntimeException('Bundled test dependencies are missing.');
        }

        return $root;
    }
}
