<?php

declare(strict_types=1);

namespace App\Formatting;

use RuntimeException;

class PintRuntime
{
    public static function prepare(string $distribution): string
    {
        $pint = $distribution . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'laravel' . DIRECTORY_SEPARATOR . 'pint' . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . 'pint';

        if (! is_file($pint) || ! is_readable($pint)) {
            throw new RuntimeException('Pint is not available in the CLI installation.');
        }

        if (! str_starts_with($distribution, 'phar://')) {

            return $pint;
        }

        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-format-runtime-' . bin2hex(random_bytes(16));

        if (! mkdir($root, 0700)) {
            throw new RuntimeException('Cannot create private format runtime.');
        }

        $destination = $root . DIRECTORY_SEPARATOR . 'pint.phar';
        register_shutdown_function(static function () use ($destination, $root): void {
            if (is_file($destination)) {
                unlink($destination);
            }

            rmdir($root);
        });

        if (! copy($pint, $destination)) {
            throw new RuntimeException('Cannot extract bundled Pint.');
        }

        return $destination;
    }
}
