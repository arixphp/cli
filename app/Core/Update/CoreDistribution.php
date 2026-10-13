<?php

declare(strict_types=1);

namespace App\Core\Update;

use JsonException;
use RuntimeException;

use function array_keys;
use function is_array;
use function is_string;

class CoreDistribution
{
    /** @param array<string, mixed> $manifest
     * @param  array<string, string>  $files
     */
    private function __construct(public readonly array $manifest, private readonly array $files) {}

    public static function bundled(string $root): self
    {
        $bundle = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'core.bundle';

        if (str_starts_with($root, 'phar://')) {

            return self::load($bundle);
        }

        return new CorePackager($root . DIRECTORY_SEPARATOR . 'core', $bundle)->check();
    }

    public static function load(string $bundle): self
    {
        if (! str_starts_with($bundle, 'phar://')) {
            CoreFiles::assertUnlinked($bundle);
        }

        $data = self::decode(CoreFiles::read($bundle));

        if (! is_array($data['manifest'] ?? null)) {
            throw new RuntimeException('Invalid core distribution manifest.');
        }

        $manifest = self::validateManifest($data['manifest'] ?? []);
        $encoded = $data['contents'] ?? null;

        if (! is_array($encoded) || array_keys($encoded) !== array_keys($manifest['files'])) {
            throw new RuntimeException('Distribution inventory does not match its manifest.');
        }

        $files = [];

        foreach ($encoded as $path => $bytes) {
            $contents = is_string($bytes) ? base64_decode($bytes, true) : false;

            if ($contents === false || hash('sha256', $contents) !== $manifest['files'][$path]) {
                throw new RuntimeException("Distribution hash mismatch: {$path}");
            }

            $files[$path] = $contents;
        }

        return new self($manifest, $files);
    }

    public static function build(string $source, string $bundle): void
    {
        new CorePackager($source, $bundle)->build();
    }

    public function contents(string $path): string
    {
        if (! isset($this->files[$path])) {
            throw new RuntimeException("File is not distributed: {$path}");
        }

        return $this->files[$path];
    }

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid core metadata JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException('Invalid core metadata.');
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function validateManifest(array $manifest): array
    {
        if (($manifest['format'] ?? null) !== 1 || ($manifest['contract'] ?? null) !== 'arix-core-v1'
            || ($manifest['php'] ?? null) !== '>=8.4.1' || ($manifest['extensions'] ?? null) !== ['mbstring']
            || ! is_array($manifest['files'] ?? null) || ! is_string($manifest['id'] ?? null)) {
            throw new RuntimeException('Unsupported core distribution manifest.');
        }

        $seen = [];

        foreach ($manifest['files'] as $path => $hash) {
            if (! is_string($path) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Invalid manifest path or hash.');
            }

            CoreFiles::assertRelative($path);

            if (strtolower($path) === 'distribution.json' || isset($seen[strtolower($path)])) {
                throw new RuntimeException("Conflicting manifest path: {$path}");
            }

            $seen[strtolower($path)] = true;
        }

        $seen['distribution.json'] = true;
        $directories = [];

        foreach (array_keys($manifest['files']) as $path) {
            $parts = explode('/', $path);
            array_pop($parts);

            while ($parts !== []) {
                $directory = implode('/', $parts);
                $folded = strtolower($directory);

                if (isset($seen[$folded]) || (isset($directories[$folded]) && $directories[$folded] !== $directory)) {
                    throw new RuntimeException("Conflicting manifest directory path: {$directory}");
                }

                $directories[$folded] = $directory;
                array_pop($parts);
            }
        }

        foreach (['autoload.php', 'functions.php', 'imports.json', 'Http/Kernel.php'] as $required) {
            if (! isset($manifest['files'][$required])) {
                throw new RuntimeException("Incomplete core distribution: {$required}");
            }
        }

        $canonical = self::manifest($manifest['files']);

        if ($canonical['id'] !== $manifest['id']) {
            throw new RuntimeException('Invalid distribution identifier hash.');
        }

        return $canonical;
    }

    /** @param array<string, string> $files
     * @return array<string, mixed>
     */
    public static function manifest(array $files): array
    {
        ksort($files);
        $manifest = ['format' => 1, 'contract' => 'arix-core-v1', 'php' => '>=8.4.1', 'extensions' => ['mbstring'], 'files' => $files];
        $manifest['id'] = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $manifest;
    }
}
