<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Core\Update\CoreFiles;
use Closure;
use RuntimeException;

class FrontendLock
{
    public function run(string $application, Closure $operation): mixed
    {
        $application = FrontendFiles::application($application);
        $file = $application . DIRECTORY_SEPARATOR . '.frontend.lock';

        CoreFiles::assertUnlinked($file);

        if (file_exists($file) && ! is_file($file)) {
            throw new RuntimeException('Invalid frontend lock.');
        }

        $mask = umask(0077);

        try {
            $lock = fopen($file, 'c');
        } finally {
            umask($mask);
        }

        if ($lock === false) {
            throw new RuntimeException('Cannot open frontend lock.');
        }

        try {

            if (! flock($lock, LOCK_EX | LOCK_NB) || ! chmod($file, 0600)) {
                throw new RuntimeException('Cannot acquire frontend lock.');
            }

            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            // A stable inode is required: never unlink a shared lock after release.
        }
    }
}
