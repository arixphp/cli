<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

use function lstat;

/**
 * Creates test links with native platform semantics.
 *
 * PHP's symlink() rejects a missing target on Windows. Dangling link fixtures
 * therefore create a temporary target, link to it, and remove the target again.
 */
class NativeLink
{
    public static function create(string $target, string $link, bool $directory = false): void
    {
        if (PHP_OS_FAMILY !== 'Windows' || file_exists($target) || is_link($target)) {
            if (! @symlink($target, $link)) {
                throw new RuntimeException("Unable to create test symlink: {$link}");
            }

            return;
        }

        $created = $directory ? @mkdir($target) : file_put_contents($target, '') !== false;

        if (! $created) {
            throw new RuntimeException("Unable to create the temporary link target: {$target}");
        }

        try {
            if (! @symlink($target, $link)) {
                throw new RuntimeException("Unable to create Windows test link: {$link}");
            }
        } finally {
            $directory ? @rmdir($target) : @unlink($target);
        }

        clearstatcache(true, $link);

        if (lstat($link) === false) {
            throw new RuntimeException("Windows test link was not created: {$link}");
        }
    }
}
