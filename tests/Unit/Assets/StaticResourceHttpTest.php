<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use App\Assets\ServerPolicyRenderer;
use Arix\Assets\AssetPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;
use Tests\Support\PublicResourceCorpus;
use Tests\Support\UrlMountProbe;

use function array_slice;
use function in_array;
use function strlen;

class StaticResourceHttpTest extends TestCase
{
    #[Test]
    public function it_detects_a_native_subdirectory_without_a_configured_base_path(): void
    {
        $runtime = getenv('ARIX_DEV_TEST_RUNTIME');

        if (! $runtime) {
            $this->markTestSkipped('Set ARIX_DEV_TEST_RUNTIME for native URL-mount checks.');
        }

        $fixture = new NewApplicationFixture();
        $server = null;

        try {
            UrlMountProbe::install($fixture);
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $policy = new ServerPolicyRenderer(new AssetPolicy())->render('caddy');
            // Normalize only while applying the relative asset/private policy; the
            // front controller still runs at its actual public /sub/index.php URL.
            $fixture->write('Caddyfile', "{\nadmin off\nauto_https off\nfrankenphp\n}\n:{$port} {\nbind 127.0.0.1\nroute {\nhandle /sub/* {\nroute {\nroot * ./sub\nuri strip_prefix /sub\n{$policy}\nroot * .\nrewrite * /sub/index.php\nphp\n}\n}\nrespond 404\n}\n}\n");
            $server = new Process([$runtime, 'run', '--config', $fixture->path('Caddyfile'), '--adapter', 'caddyfile'], $fixture->root, ['XDG_DATA_HOME' => $fixture->root, 'XDG_CONFIG_HOME' => $fixture->root, 'XDEBUG_MODE' => 'off'], timeout: null);
            $server->start();
            $deadline = microtime(true) + 10;

            do {
                $response = $this->request($port, '/sub/about');

                if ($response[0] !== 0) {
                    break;
                }

                usleep(50000);
            } while ($server->isRunning() && microtime(true) < $deadline);

            $this->assertSame(200, $response[0], $server->getErrorOutput() . $response[1]);
            $data = json_decode($response[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('/about', $data['path']);
            $this->assertSame('/sub/index.php', $data['script']);
            $this->assertSame($fixture->path('sub/index.php'), $data['filename']);
            $this->assertSame("http://127.0.0.1:{$port}/sub/about", $data['url']);
            $this->assertSame("http://127.0.0.1:{$port}/sub/styles/app.css", $data['asset']);
            $this->assertSame('body{}', $this->request($port, '/sub/styles/app.css')[1]);
            $this->assertSame([200, ''], array_slice($this->request($port, '/sub/about', 'HEAD'), 0, 2));

            foreach (['/.env', '/.env.bak', '/%2eenv', '/core/secret.php'] as $path) {

                foreach (['GET', 'HEAD'] as $method) {
                    $response = $this->request($port, "/sub{$path}", $method);
                    $this->assertTrue(in_array($response[0], [400, 403, 404], true));
                    $this->assertStringNotContainsString('PRIVATE', $response[1]);
                }

            }
        } finally {
            $server?->stop(1);
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_serves_only_typed_static_files_at_root_and_under_a_prefix(): void
    {
        $runtime = getenv('ARIX_DEV_TEST_RUNTIME');

        if (! $runtime) {
            $this->markTestSkipped('Set ARIX_DEV_TEST_RUNTIME for native Caddy checks.');
        }

        $fixture = new NewApplicationFixture();
        $server = null;

        try {
            $fixture->write('index.php', '<?php echo $_SERVER["REQUEST_METHOD"];');
            $fixture->write('fonts/inter.woff2', 'FONT');
            $fixture->write('media/nested/logo.webp', 'IMAGE');
            $fixture->write('media/movie.mp4', '0123456789');

            foreach (PublicResourceCorpus::deniedPaths() as $path) {

                if (! str_contains($path, '%') && ! str_contains($path, '..') && ! str_ends_with($path, '/')) {
                    $fixture->write(ltrim($path, '/'), 'PRIVATE');
                }
            }

            $fixture->write('media/index.php', '<?php file_put_contents(__DIR__."/executed", "BAD"); echo "PRIVATE";');
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $policy = new ServerPolicyRenderer(new AssetPolicy())->render('caddy');
            $fixture->write('Caddyfile', "{\nadmin off\nauto_https off\nfrankenphp\n}\n:{$port} {\nbind 127.0.0.1\nroot * .\nroute {\nhandle_path /site/* {\nroute {\n{$policy}\nrewrite * /index.php\nphp\n}\n}\nhandle {\nroute {\n{$policy}\nrewrite * /index.php\nphp\n}\n}\n}\n}\n");
            $server = new Process([$runtime, 'run', '--config', $fixture->path('Caddyfile'), '--adapter', 'caddyfile'], $fixture->root, ['XDG_DATA_HOME' => $fixture->root, 'XDG_CONFIG_HOME' => $fixture->root], timeout: null);
            $server->start();
            $deadline = microtime(true) + 10;

            do {
                $ready = $this->request($port, '/article');

                if ($ready[0] === 200) {
                    break;
                }

                usleep(50000);
            } while ($server->isRunning() && microtime(true) < $deadline);

            $this->assertSame(200, $ready[0], $server->getErrorOutput());

            foreach (['', '/site'] as $prefix) {

                foreach (['/fonts/inter.woff2' => ['FONT', 'font/woff2'], '/media/nested/logo.webp' => ['IMAGE', 'image/webp']] as $path => [$bytes, $mime]) {
                    [$status, $body, $headers] = $this->request($port, "{$prefix}{$path}");
                    $this->assertSame(200, $status, $path);
                    $this->assertSame($bytes, $body);
                    $this->assertStringStartsWith($mime, $headers['content-type']);
                    $this->assertSame('nosniff', $headers['x-content-type-options']);
                    $head = $this->request($port, "{$prefix}{$path}", 'HEAD');
                    $this->assertSame(200, $head[0]);
                    $this->assertSame('', $head[1]);
                    $this->assertSame(304, $this->request($port, "{$prefix}{$path}", headers: ["If-None-Match: {$headers['etag']}"])[0]);
                }

                foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
                    $response = $this->request($port, "{$prefix}/media/nested/logo.webp", $method);
                    $this->assertSame(405, $response[0]);
                    $this->assertSame('GET, HEAD', $response[2]['allow'] ?? null);
                }

                foreach (PublicResourceCorpus::deniedPaths() as $path) {

                    foreach (['GET', 'HEAD'] as $method) {
                        [$status, $body] = $this->request($port, "{$prefix}{$path}", $method);
                        $this->assertTrue(in_array($status, [400, 403, 404], true), "{$method} {$path}: {$status}");
                        $this->assertStringNotContainsString('PRIVATE', $body);
                    }

                }

                $range = $this->request($port, "{$prefix}/media/movie.mp4", headers: ['Range: bytes=2-5']);
                $this->assertSame(206, $range[0]);
                $this->assertSame('2345', $range[1]);
                $this->assertSame('bytes 2-5/10', $range[2]['content-range']);
                $this->assertSame(416, $this->request($port, "{$prefix}/media/movie.mp4", headers: ['Range: bytes=99-100'])[0]);
                $this->assertSame('POST', $this->request($port, "{$prefix}/article", 'POST')[1]);
            }

            $this->assertFileDoesNotExist($fixture->path('media/executed'));
        } finally {
            $server?->stop(1);
            $fixture->cleanup();
        }
    }

    private function request(int $port, string $path, string $method = 'GET', array $headers = []): array
    {
        $curl = curl_init("http://127.0.0.1:{$port}{$path}");
        $responseHeaders = [];
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 1000, CURLOPT_PATH_AS_IS => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower($key)] = trim($value);
            }

            return strlen($line);
        }]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, $body ?: '', $responseHeaders];
    }
}
