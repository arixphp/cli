<?php

declare(strict_types=1);

namespace App\Js\Update;

use App\Core\Update\CoreFiles;
use JsonException;
use RuntimeException;

use function in_array;
use function is_array;
use function is_string;

class JsDistribution
{
    /** @param array<string, string> $releases */
    private function __construct(
        public readonly string $version,
        public readonly string $hash,
        public readonly array $releases,
        private readonly string $contents,
    ) {}

    public static function bundled(string $root): self
    {
        $base = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR;
        $contents = CoreFiles::read("{$base}arix.js");
        $releases = self::validateManifest(self::decode(CoreFiles::read($base . 'manifest.json')));
        $version = self::parseVersion($contents);
        $hash = hash('sha256', $contents);

        if (($releases[$version] ?? null) !== $hash) {
            throw new RuntimeException("The canonical framework is not a recorded release: {$version}");
        }

        return new self($version, $hash, $releases, $contents);
    }

    public function contents(): string
    {

        return $this->contents;
    }

    public function isKnownRelease(string $hash): bool
    {

        return in_array($hash, $this->releases, true);
    }

    public function releaseOf(string $hash): string|null
    {
        $version = array_search($hash, $this->releases, true);

        return is_string($version) ? $version : null;
    }

    public static function parseVersion(string $contents): string
    {
        $matched = preg_match('/export\s+const\s+version\s*=\s*([\'"])([^\'"]+)\1/', $contents, $matches);

        if ($matched !== 1) {
            throw new RuntimeException('The framework does not declare a version.');
        }

        return $matches[2];
    }

    /** @return array<string, string> */
    public static function validateManifest(array $manifest): array
    {
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['releases'] ?? null) || ($manifest['releases'] ?? []) === []) {
            throw new RuntimeException('Unsupported JavaScript distribution manifest.');
        }

        $releases = [];

        foreach ($manifest['releases'] as $version => $hash) {
            if (! is_string($version) || $version === '' || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new RuntimeException('Invalid manifest release or hash.');
            }

            $releases[$version] = $hash;
        }

        return $releases;
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JavaScript metadata JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException('Invalid JavaScript metadata.');
        }

        return $data;
    }
}
