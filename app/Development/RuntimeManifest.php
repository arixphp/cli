<?php

declare(strict_types=1);

namespace App\Development;

use RuntimeException;

use function is_array;
use function is_int;
use function is_string;

class RuntimeManifest
{
    public readonly string $version;

    private readonly array $targets;

    public function __construct(string $path)
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Cannot read bundled FrankenPHP manifest.');
        }

        $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($manifest) || ! is_string($manifest['version'] ?? null) || preg_match('/\Av[0-9]+\.[0-9]+\.[0-9]+\z/', $manifest['version']) !== 1 || ! is_array($manifest['targets'] ?? null)) {
            throw new RuntimeException('Invalid FrankenPHP release manifest.');
        }

        $this->version = $manifest['version'];
        $this->targets = $manifest['targets'];
    }

    public function target(string $platform): array
    {
        $target = $this->targets[$platform] ?? null;

        if (! is_array($target) || ! is_string($target['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $target['sha256']) !== 1 || $target['sha256'] === hash('sha256', '') || ! is_int($target['max_bytes'] ?? null) || $target['max_bytes'] < 1 || ! is_string($target['executable'] ?? null) || preg_match('/\A[A-Za-z0-9_.-]+\z/', $target['executable']) !== 1 || ! str_starts_with($target['url'] ?? '', "https://github.com/php/frankenphp/releases/download/{$this->version}/")) {
            throw new RuntimeException("No valid pinned FrankenPHP artifact for {$platform}.");
        }

        return $target;
    }
}
