<?php

declare(strict_types=1);

namespace App\Installation;

use RuntimeException;

class InstallationFiles
{
    public function createWorkspace(string $parent): string
    {
        $path = $parent . DIRECTORY_SEPARATOR . '.arix-new-' . bin2hex(random_bytes(16));

        if (! @mkdir($path, 0700)) {
            throw new RuntimeException("Cannot create installation workspace in {$parent}. Check permissions and disk space.");
        }

        return $path;
    }

    public function removeTree(string $path): void
    {
        clearstatcache(true, $path);

        if (is_link($path) || is_file($path)) {
            // Windows directory links (including dangling ones) require
            // rmdir; never recurse into a link's target.
            if (! @unlink($path) && ! (PHP_OS_FAMILY === 'Windows' && is_link($path) && @rmdir($path))) {
                throw new RuntimeException("Cannot remove installation temporary file: {$path}.");
            }

            return;
        }

        if (! file_exists($path)) {

            return;
        }

        $entries = @scandir($path);

        if ($entries === false) {
            throw new RuntimeException("Cannot inspect installation workspace for cleanup: {$path}.");
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        if (! @rmdir($path)) {
            throw new RuntimeException("Cannot remove installation workspace: {$path}.");
        }
    }
}
