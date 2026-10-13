<?php

declare(strict_types=1);

namespace App\Installation;

use JsonException;
use RuntimeException;

use function is_array;
use function is_string;

class GitHubSkeleton implements SkeletonSource
{
    public function __construct(private readonly HttpsDownloader $downloader) {}

    public function download(string $workspace): string
    {
        $metadata = $workspace . DIRECTORY_SEPARATOR . 'commit.json';
        $this->downloader->download('https://api.github.com/repos/arixphp/app/commits/main', $metadata, 1048576);
        $contents = @file_get_contents($metadata);

        if ($contents === false) {
            throw new RuntimeException('Cannot read GitHub commit metadata.');
        }

        try {
            $response = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid GitHub commit response.', previous: $exception);
        }

        $sha = is_array($response) ? ($response['sha'] ?? null) : null;

        if (! is_string($sha) || preg_match('/\A[a-f0-9]{40}\z/', $sha) !== 1) {
            throw new RuntimeException('Invalid GitHub commit SHA. Refusing to download an unpinned skeleton.');
        }

        $this->downloader->download("https://codeload.github.com/arixphp/app/zip/{$sha}", $workspace . DIRECTORY_SEPARATOR . 'skeleton.zip', 33554432);

        return $sha;
    }
}
