<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;

class ProductionCacheTest extends TestCase
{
    #[Test]
    public function it_revalidates_imports_without_imposing_the_policy_on_media(): void
    {
        $runtime = getenv('ARIX_DEV_TEST_RUNTIME');
        $configuration = getenv('ARIX_DEV_TEST_CADDYFILE');

        if (! $runtime || ! $configuration) {
            $this->markTestSkipped('Set ARIX_DEV_TEST_RUNTIME and ARIX_DEV_TEST_CADDYFILE for production header integration.');
        }

        $fixture = new NewApplicationFixture();
        $fixture->write('index.php', '<?php header("Content-Type: text/html"); echo "<p>HTML</p>";');
        $fixture->write('Caddyfile', file_get_contents($configuration));
        $fixture->write('styles/app.css', '@import "base.css";');
        $fixture->write('styles/base.css', 'body{color:red}');
        $fixture->write('scripts/app.js', 'import "./arix.js";');
        $fixture->write('scripts/arix.js', 'export const value = 1;');
        $fixture->write('assets/image.png', 'image');

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);

        fclose($socket);

        $server = new Process([$runtime, 'run', '--config', $fixture->path('Caddyfile'), '--adapter', 'caddyfile'], $fixture->root, ['SERVER_NAME' => "http://127.0.0.1:{$port}", 'XDG_DATA_HOME' => $fixture->root, 'XDG_CONFIG_HOME' => $fixture->root], timeout: null);
        $server->start();

        try {
            $deadline = microtime(true) + 10;

            do {
                $html = $this->request($port, '/');

                if ($html[0] === 200) {
                    break;
                }

                usleep(50000);
            } while ($server->isRunning() && microtime(true) < $deadline);

            $this->assertSame(200, $html[0], $server->getErrorOutput());
            $this->assertSame('no-cache', $html[2]['cache-control'] ?? null);

            foreach (['/styles/app.css', '/styles/base.css', '/scripts/app.js', '/scripts/arix.js'] as $path) {
                $first = $this->request($port, $path);

                $this->assertSame('no-cache', $first[2]['cache-control'] ?? null, $path);

                $etag = $first[2]['etag'];

                $this->assertSame(304, $this->request($port, $path, ['If-None-Match: ' . $etag])[0]);

                if ($path === '/styles/base.css' || $path === '/scripts/arix.js') {
                    $fixture->write(ltrim($path, '/'), 'new content with a different size');

                    touch($fixture->path(ltrim($path, '/')), time() + 2);

                    $updated = $this->request($port, $path, ['If-None-Match: ' . $etag]);

                    $this->assertSame(200, $updated[0]);
                    $this->assertSame('new content with a different size', $updated[1]);
                }
            }

            $this->assertNotSame('no-cache', $this->request($port, '/assets/image.png')[2]['cache-control'] ?? null);
        } finally {
            $server->stop(1);
            $fixture->cleanup();
        }
    }

    private function request(int $port, string $path, array $headers = []): array
    {
        $curl = curl_init("http://127.0.0.1:{$port}{$path}");
        $responseHeaders = [];

        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500, CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower($key)] = trim($value);
            }

            return strlen($line);
        }]);

        $body = curl_exec($curl);

        return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body ?: '', $responseHeaders];
    }
}
