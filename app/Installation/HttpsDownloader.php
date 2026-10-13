<?php

declare(strict_types=1);

namespace App\Installation;

use RuntimeException;

use function in_array;

class HttpsDownloader
{
    public function __construct(private readonly HttpTransport $transport) {}

    public function download(string $url, string $destination, int $maxBytes): void
    {
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $this->assertSafeUrl($url);
            $response = $this->transport->request($url, $destination, $maxBytes);
            clearstatcache(true, $destination);
            $size = @filesize($destination);

            if ($size === false || $size > $maxBytes) {
                throw new RuntimeException('Download exceeds the size limit or cannot be inspected.');
            }

            if ($response->status === 200) {

                return;
            }

            if (! in_array($response->status, [301, 302, 303, 307, 308], true) || $response->location === null) {
                throw new RuntimeException("GitHub download failed with HTTP {$response->status}. Check connectivity or GitHub rate limits and retry.");
            }

            if ($redirect === 3) {
                throw new RuntimeException('GitHub download exceeded the redirect limit.');
            }

            $location = $response->location;

            if (str_starts_with($location, '/') && ! str_starts_with($location, '//')) {
                $host = parse_url($url, PHP_URL_HOST);
                $location = "https://{$host}{$location}";
            }

            $url = $location;
        }
    }

    private function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1
            || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', ['api.github.com', 'github.com', 'codeload.github.com'], true)
            || ($parts['port'] ?? 443) !== 443
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Unsafe download URL or redirect. Only trusted GitHub HTTPS origins are allowed.');
        }
    }
}
