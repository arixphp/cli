<?php

declare(strict_types=1);

namespace App\Installation;

use RuntimeException;

class ApplicationPublisher
{
    public function publish(InstallationTarget $target, string $workspace): void
    {
        $prepared = $workspace . DIRECTORY_SEPARATOR . 'application';
        $previous = $workspace . DIRECTORY_SEPARATOR . 'previous';

        if (is_link($prepared) || ! is_dir($prepared) || ! @chmod($prepared, 0755)) {
            throw new RuntimeException('Cannot prepare application directory for publication.');
        }

        $target->assertUnchanged();
        $hasPrevious = $target->identity !== null;

        if ($hasPrevious && ! $this->move($target->path, $previous)) {
            throw new RuntimeException("Cannot prepare empty destination for publication: {$target->path}.");
        }

        clearstatcache(true, $target->path);

        if (file_exists($target->path) || is_link($target->path) || ! $this->move($prepared, $target->path)) {
            if ($hasPrevious) {
                clearstatcache(true, $target->path);

                if (file_exists($target->path) || is_link($target->path) || ! $this->move($previous, $target->path)) {
                    throw new RecoveryFailed("Cannot publish or restore the original empty destination. Nothing at the destination was removed. Inspect retained workspace: {$workspace}");
                }
            }

            throw new RuntimeException("Cannot publish application to {$target->path}. Check permissions and concurrent changes.");
        }
    }

    protected function move(string $source, string $destination): bool
    {

        return @rename($source, $destination);
    }
}
