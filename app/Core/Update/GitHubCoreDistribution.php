<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Installation\HttpsDownloader;
use JsonException;
use RuntimeException;
use ZipArchive;

use function is_array;
use function is_int;
use function is_string;
use function strlen;

class GitHubCoreDistribution
{
    private const int MAX_BUNDLE_BYTES = 33554432;

    public function __construct(
        private readonly HttpsDownloader $downloader,
        private readonly string|null $temporaryRoot = null,
    ) {}

    public function latest(): RemoteCoreDistribution
    {
        if (! extension_loaded('curl') || ! extension_loaded('zip')) {
            throw new RuntimeException('Online core updates require ext-curl and ext-zip. Use core:update without --online for the embedded bundle.');
        }

        $root = realpath($this->temporaryRoot ?? sys_get_temp_dir());

        if ($root === false) {
            throw new RuntimeException('Cannot resolve the core download temporary directory.');
        }

        $workspace = $root . DIRECTORY_SEPARATOR . 'arix-core-download-' . bin2hex(random_bytes(12));

        if (! @mkdir($workspace, 0700)) {
            throw new RuntimeException('Cannot create a private core download directory.');
        }

        try {
            if (! @chmod($workspace, 0700)) {
                throw new RuntimeException('Cannot secure the core download directory.');
            }

            $release = $this->metadata('https://api.github.com/repos/arixphp/cli/releases/latest', $workspace, 'release');

            if (! $this->isStableRelease($release)) {
                throw new RuntimeException('Invalid stable GitHub core release. Refusing to download.');
            }

            $tag = $release['tag_name'];
            $commit = $this->metadata('https://api.github.com/repos/arixphp/cli/commits/' . rawurlencode($tag), $workspace, 'commit');
            $sha = $commit['sha'] ?? null;

            if (! is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/', $sha) !== 1) {
                throw new RuntimeException('Invalid GitHub core commit SHA. Refusing an unpinned download.');
            }

            $archive = $workspace . DIRECTORY_SEPARATOR . 'release.zip';

            $this->downloader->download("https://codeload.github.com/arixphp/cli/zip/{$sha}", $archive, 33554432);

            $bundle = $workspace . DIRECTORY_SEPARATOR . 'core.bundle';

            CoreFiles::write($bundle, $this->bundleContents($archive, $sha));

            $distribution = CoreDistribution::load($bundle);

            return new RemoteCoreDistribution($distribution, ltrim($tag, 'v'), $sha);
        } finally {
            $this->cleanupWorkspace($workspace);
        }
    }

    /** @param array<string, mixed> $release */
    private function isStableRelease(array $release): bool
    {
        $tag = $release['tag_name'] ?? null;
        $number = '(?:0|[1-9][0-9]*)';

        return ($release['draft'] ?? null) === false
            && ($release['prerelease'] ?? null) === false
            && is_string($tag)
            && strlen($tag) <= 128
            && preg_match("/\Av?{$number}\.{$number}\.{$number}(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/", $tag) === 1;
    }

    private function cleanupWorkspace(string $workspace): void
    {
        $clean = true;

        foreach (['release.json', 'commit.json', 'release.zip', 'core.bundle'] as $name) {
            $file = $workspace . DIRECTORY_SEPARATOR . $name;

            if (file_exists($file) || is_link($file)) {
                $clean = @unlink($file) && $clean;
            }
        }

        if (! @rmdir($workspace) || ! $clean) {
            throw new RuntimeException("Cannot clean the private core download directory: {$workspace}");
        }
    }

    /** @return array<string, mixed> */
    private function metadata(string $url, string $workspace, string $kind): array
    {
        $file = $workspace . DIRECTORY_SEPARATOR . "{$kind}.json";
        $this->downloader->download($url, $file, 1048576);

        try {
            $data = json_decode(CoreFiles::read($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid GitHub core {$kind} response.", previous: $exception);
        }

        if (! is_array($data)) {
            throw new RuntimeException("Invalid GitHub core {$kind} response.");
        }

        return $data;
    }

    private function bundleContents(string $archive, string $sha): string
    {
        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Cannot open core release ZIP. The download is corrupt or not a ZIP archive.');
        }

        try {
            if ($zip->numFiles > 20000) {
                throw new RuntimeException('Core release ZIP exceeds the entry limit.');
            }

            $entry = "cli-{$sha}/resources/core.bundle";
            $found = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                if ($zip->getNameIndex($index) === $entry) {
                    if ($found !== null) {
                        throw new RuntimeException('Duplicate bundle in core release ZIP.');
                    }

                    $found = $index;
                }
            }

            $stat = $found === null ? false : $zip->statIndex($found);

            if ($stat === false || ! is_int($stat['size'] ?? null) || $stat['size'] < 1
                || $stat['size'] > self::MAX_BUNDLE_BYTES || ($stat['encryption_method'] ?? 0) !== 0) {
                throw new RuntimeException('Core release bundle is missing, encrypted, empty or exceeds the size limit.');
            }

            if (! $zip->getExternalAttributesIndex($found, $system, $attributes)
                || ($system === ZipArchive::OPSYS_UNIX && ($attributes >> 16 & 0170000) === 0120000)) {
                throw new RuntimeException('Refusing a linked core release bundle.');
            }

            $contents = $zip->getFromIndex($found, self::MAX_BUNDLE_BYTES + 1);

            if ($contents === false || strlen($contents) !== $stat['size']) {
                throw new RuntimeException('Cannot read the complete core release bundle.');
            }

            return $contents;
        } finally {
            $zip->close();
        }
    }
}
