<?php

declare(strict_types=1);

namespace App\Installation;

use RuntimeException;

use function in_array;

class PlatformRequirements
{
    public function verify(): void
    {
        $missing = [];

        foreach (['curl', 'zip'] as $extension) {
            if (! extension_loaded($extension)) {
                $missing[] = "ext-{$extension}";
            }
        }

        if ($missing !== []) {
            $requirements = implode(', ', $missing);
            throw new RuntimeException("The installer requires {$requirements}. Enable these extensions in the CLI PHP runtime or use a native Arix build that includes them.");
        }

        if (! in_array('https', curl_version()['protocols'], true)) {
            throw new RuntimeException('The installer requires HTTPS support in ext-curl.');
        }
    }
}
