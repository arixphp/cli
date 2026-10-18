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

use function in_array;
use function strlen;

class ApacheResourceHttpTest extends TestCase
{
    #[Test]
    public function it_checks_native_apache_exposure_and_php_cache_headers(): void
    {
        $runtime = getenv('ARIX_APACHE_TEST_RUNTIME');
        $modules = getenv('ARIX_APACHE_TEST_MODULES');
        $cgi = getenv('ARIX_APACHE_TEST_PHP_CGI');
        $configuration = getenv('ARIX_APACHE_TEST_HTACCESS');

        if (! $runtime || ! $modules || ! $cgi || ! $configuration) {
            $this->markTestSkipped('Set ARIX_APACHE_TEST_RUNTIME, MODULES, PHP_CGI and HTACCESS for native Apache checks.');
        }

        $fixture = new NewApplicationFixture();
        $server = null;
        $php = null;

        try {
            $root = $fixture->root;
            $fixture->write('.htaccess', file_get_contents($configuration));
            $fixture->write('index.php', '<?php header("Content-Type: text/html"); if (isset($_GET["sensitive"])) { header("Cache-Control: no-store"); } echo $_SERVER["REQUEST_METHOD"];');
            $fixture->write('fonts/inter.woff2', 'FONT');
            $fixture->write('media/nested/logo.webp', 'IMAGE');
            $fixture->write('media/movie.mp4', '0123456789');

            foreach (PublicResourceCorpus::deniedPaths() as $path) {

                if (! str_contains($path, '%') && ! str_contains($path, '..') && ! str_ends_with($path, '/')) {
                    $fixture->write(ltrim($path, '/'), 'PRIVATE');
                }
            }

            foreach (['fonts', 'media'] as $directory) {
                $fixture->write("{$directory}/.htaccess", new ServerPolicyRenderer(new AssetPolicy())->renderDirectory($directory));
            }

            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $loads = '';

            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $phpPort = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            $php = new Process([$cgi, '-b', "127.0.0.1:{$phpPort}"], $root, ['XDEBUG_MODE' => 'off'], timeout: null);
            $php->start();

            foreach (['mpm_prefork', 'authz_core', 'authz_host', 'unixd', 'mime', 'dir', 'rewrite', 'headers', 'proxy', 'proxy_fcgi'] as $module) {
                $loads .= "LoadModule {$module}_module \"{$modules}/mod_{$module}.so\"\n";
            }

            $fixture->write('httpd.conf', <<<APACHE
ServerRoot "{$root}"
PidFile "{$root}/httpd.pid"
Listen 127.0.0.1:{$port}
ServerName localhost
{$loads}
ErrorLog "{$root}/error.log"
LogLevel warn
TypesConfig /dev/null
TraceEnable Off
ProxyFCGIBackendType GENERIC
DocumentRoot "{$root}"
<Directory "{$root}">
    Require all granted
    AllowOverride All
    Options FollowSymLinks ExecCGI
    <FilesMatch "\\.php$">
        SetHandler "proxy:fcgi://127.0.0.1:{$phpPort}"
    </FilesMatch>
</Directory>
APACHE);
            $file = $fixture->path('httpd.conf');
            // Apache signals its process group on shutdown: isolate it from the test runner.
            $server = new Process([PHP_BINARY, '-r', 'posix_setsid(); pcntl_exec($argv[1], array_slice($argv, 2));', $runtime, '-f', $file, '-X'], $root, ['XDEBUG_MODE' => 'off'], timeout: null);
            $server->start();
            $deadline = microtime(true) + 10;

            do {
                $response = $this->request($port, '/');

                if ($response[0] !== 0) {
                    break;
                }

                usleep(50000);
            } while ($server->isRunning() && microtime(true) < $deadline);

            $errors = fn (): string => $server->getErrorOutput() . (is_file($fixture->path('error.log')) ? file_get_contents($fixture->path('error.log')) : '');
            $this->assertSame(200, $response[0], $errors() . $response[1] . $php->getErrorOutput());
            $this->assertSame('GET', $response[1], $errors());
            $this->assertSame('no-cache', $response[2]['cache-control'] ?? null);
            $this->assertSame('no-store', $this->request($port, '/?sensitive=1')[2]['cache-control'] ?? null);

            foreach (['/fonts/inter.woff2' => ['FONT', 'font/woff2'], '/media/nested/logo.webp' => ['IMAGE', 'image/webp']] as $path => [$body, $mime]) {
                $response = $this->request($port, $path);
                $this->assertSame(200, $response[0], $errors());
                $this->assertSame($body, $response[1]);
                $this->assertStringStartsWith($mime, $response[2]['content-type']);
                $this->assertSame('nosniff', $response[2]['x-content-type-options']);
                $this->assertSame(200, $this->request($port, $path, 'HEAD')[0]);
            }

            foreach (PublicResourceCorpus::deniedPaths() as $path) {
                [$status, $body] = $this->request($port, $path);
                $this->assertTrue(in_array($status, [400, 403, 404], true), "{$path}: {$status}");
                $this->assertStringNotContainsString('PRIVATE', $body);
            }

            foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
                $response = $this->request($port, '/media/nested/logo.webp', $method);
                $this->assertSame(405, $response[0], $errors());
                // RFC list parsing ignores empty elements (Apache adds an empty default Allow).
                $this->assertSame(['GET', 'HEAD'], array_values(array_filter(array_map('trim', explode(',', $response[2]['allow'] ?? '')))), json_encode($response[2]));
            }

            $this->assertSame('POST', $this->request($port, '/article', 'POST')[1]);
            $range = $this->request($port, '/media/movie.mp4', headers: ['Range: bytes=2-5']);
            $this->assertSame(206, $range[0]);
            $this->assertSame('2345', $range[1]);
        } finally {
            $server?->stop(1);
            $php?->stop(1);
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
                $key = strtolower($key);
                $responseHeaders[$key] = isset($responseHeaders[$key]) ? $responseHeaders[$key] . ', ' . trim($value) : trim($value);
            }

            return strlen($line);
        }]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [$status, $body ?: '', $responseHeaders];
    }
}
