<?php

declare(strict_types=1);

namespace App\Frontend;

use RuntimeException;

use function is_array;
use function is_string;

class ReleaseManifest
{
    /** @param array<string, mixed> $manifest
     * @param list<string> $files
     * @return array<string, array<string, string>> */
    public static function validate(array $manifest, array $files): array
    {
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['releases'] ?? null) || $manifest['releases'] === []) {
            throw new RuntimeException('Unsupported frontend distribution manifest.');
        }

        $releases = [];

        foreach ($manifest['releases'] as $version => $inventory) {
            if (! is_string($version) || $version === '' || ! is_array($inventory)) {
                throw new RuntimeException('Invalid manifest release.');
            }

            if (array_diff(array_keys($inventory), $files) !== [] || array_diff($files, array_keys($inventory)) !== []) {
                throw new RuntimeException("Incomplete or unexpected manifest release files: {$version}");
            }

            foreach ($inventory as $hash) {
                if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new RuntimeException('Invalid manifest release hash.');
                }
            }

            $releases[$version] = $inventory;
        }

        return $releases;
    }
}
