<?php

declare(strict_types=1);

namespace App\Css\Update;

use App\Core\Update\CoreFiles;
use App\Frontend\ReleaseManifest;
use JsonException;
use RuntimeException;

use function is_array;

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

        foreach ($contents as $file => $body) {
            if (preg_match_all('/Arix CSS version:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $body, $matches) !== 1 || $matches[1][0] !== $version) {
                throw new RuntimeException("CSS version header does not match release: {$file}");
            }
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

        return ReleaseManifest::validate($manifest, self::FILES);
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
