<?php

declare(strict_types=1);

namespace App\Development;

use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class SessionFiles
{
    public readonly string $directory;

    public function __construct(string|null $parent = null)
    {
        $parent ??= realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();

        DevelopmentTarget::assertUnlinked($parent);

        $directory = $parent . DIRECTORY_SEPARATOR . 'arix-dev-' . bin2hex(random_bytes(16));

        if (! @mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create private development directory.');
        }

        $this->directory = $directory;
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->directory);
    }
}
