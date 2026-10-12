<?php

declare(strict_types=1);

namespace App\Css\Update;

use App\Core\Update\CoreFiles;
use JsonException;
use RuntimeException;

use function in_array;
use function is_array;
use function is_string;

class CssDistribution
{
    public const array FILES = ['base.css', 'components.css', 'tokens.css', 'utilities.css'];

    /** @param array<string, string> $hashes
     * @param array<string, array<string, string>> $releases
     * @param array<string, string> $contents */
    private function __construct(
        public readonly string $version,
        public readonly array $hashes,
        public readonly array $releases,
        private readonly array $contents,
    ) {}

    public static function bundled(string $root): self
    {
        $base = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR;
        $contents = [];

        foreach (self::FILES as $file) {
            $contents[$file] = CoreFiles::read($base . $file);
        }

        $releases = self::validateManifest(self::decode(CoreFiles::read($base . 'manifest.json')));
        $hashes = [];

        foreach ($contents as $file => $body) {
            $hashes[$file] = hash('sha256', $body);
        }

        $version = self::releaseOfHashes($releases, $hashes);

        if ($version === null) {
            throw new RuntimeException('The canonical styles are not a recorded release.');
        }

        return new self($version, $hashes, $releases, $contents);
    }

    public function contents(string $file): string
    {
        if (! isset($this->contents[$file])) {
            throw new RuntimeException("File is not distributed: {$file}");
        }

        return $this->contents[$file];
    }

    /** @param array<string, string|null> $hashes */
    public function isKnownSet(array $hashes): bool
    {

        return $this->releaseOf($hashes) !== null;
    }

    /** @param array<string, string|null> $hashes */
    public function releaseOf(array $hashes): string|null
    {

        return self::releaseOfHashes($this->releases, $hashes);
    }

    /** @param array<string, array<string, string>> $releases
     * @param array<string, string|null> $hashes */
    private static function releaseOfHashes(array $releases, array $hashes): string|null
    {
        foreach ($releases as $version => $recorded) {
            $match = true;

            foreach (self::FILES as $file) {
                if (($hashes[$file] ?? null) !== ($recorded[$file] ?? null)) {
                    $match = false;

                    break;
                }
            }

            if ($match) {

                return $version;
            }
        }

        return null;
    }

    /** @return array<string, array<string, string>> */
    public static function validateManifest(array $manifest): array
    {
        if (($manifest['format'] ?? null) !== 1 || ! is_array($manifest['releases'] ?? null) || ($manifest['releases'] ?? []) === []) {
            throw new RuntimeException('Unsupported styles distribution manifest.');
        }

        $releases = [];

        foreach ($manifest['releases'] as $version => $files) {
            if (! is_string($version) || $version === '' || ! is_array($files)) {
                throw new RuntimeException('Invalid manifest release.');
            }

            $recorded = [];

            foreach ($files as $file => $hash) {
                if (! in_array($file, self::FILES, true) || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                    throw new RuntimeException('Invalid manifest release file or hash.');
                }

                $recorded[$file] = $hash;
            }

            if (array_diff(self::FILES, array_keys($recorded)) !== []) {
                throw new RuntimeException("Incomplete manifest release: {$version}");
            }

            $releases[$version] = $recorded;
        }

        return $releases;
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid styles metadata JSON.', previous: $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException('Invalid styles metadata.');
        }

        return $data;
    }
}
