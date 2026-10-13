<?php

declare(strict_types=1);

namespace App\Installation;

use CurlHandle;
use RuntimeException;

use function strlen;

class CurlTransport implements HttpTransport
{
    public function request(string $url, string $destination, int $maxBytes): HttpResponse
    {
        $download = new BoundedDownload($destination, $maxBytes);
        $handle = curl_init();
        $location = null;
        $writeError = null;

        try {
            curl_setopt_array($handle, [
                CURLOPT_URL => $url,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'Arix CLI',
                CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
                CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $header) use (&$location): int {
                    if (str_starts_with($header, 'HTTP/')) {
                        $location = null;
                    }

                    if (strncasecmp($header, 'Location:', 9) === 0) {
                        $location = trim(substr($header, 9));
                    }

                    return strlen($header);
                },
                CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $data) use ($download, &$writeError): int {
                    try {

                        return $download->write($data);
                    } catch (RuntimeException $exception) {
                        $writeError = $exception;

                        return 0;
                    }
                },
            ]);
            $success = curl_exec($handle);

            if ($writeError !== null) {
                throw $writeError;
            }

            if ($success === false) {
                $error = curl_error($handle);
                throw new RuntimeException("HTTPS request failed: {$error}. Check connectivity and trusted CA certificates.");
            }

            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $location);
        } finally {
            unset($handle);
            $download->close();
        }
    }
}
