<?php

declare(strict_types=1);

namespace App\Development;

use App\Installation\HttpTransport;
use RuntimeException;

use function in_array;

class RuntimeDownloader
{
    public function __construct(private readonly HttpTransport $transport) {}

    public function download(array $target, string $destination): void
    {
        $url = $target['url'];
        for ($redirect = 0; $redirect <= 3; $redirect++) {
            $parts = parse_url($url);
            if ($parts === false || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1 || ($parts['scheme'] ?? '') !== 'https' || ! in_array($parts['host'] ?? '', ['github.com', 'release-assets.githubusercontent.com'], true) || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                throw new RuntimeException('Unsafe FrankenPHP download URL or redirect.');
            }
            $response = $this->transport->request($url, $destination, $target['max_bytes']);
            clearstatcache(true, $destination);
            $size = @filesize($destination);
            if ($size === false || $size > $target['max_bytes']) {
                throw new RuntimeException('FrankenPHP download exceeds size limit.');
            }
            if ($response->status === 200) {
                if ($size === 0 || ! hash_equals($target['sha256'], hash_file('sha256', $destination) ?: '')) {
                    throw new RuntimeException('FrankenPHP checksum mismatch; refusing to execute download.');
                }

                return;
            }
            if ($redirect === 3 || ! in_array($response->status, [301, 302, 303, 307, 308], true) || $response->location === null) {
                throw new RuntimeException("FrankenPHP download failed with HTTP {$response->status} or too many redirects.");
            }
            $url = $response->location;
        }
    }
}
