<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\CaddyConfiguration;
use App\Development\DevOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;

use function is_array;

class CaddyConfigurationTest extends TestCase
{
    private NewApplicationFixture $fixture;

    private string $runtime;

    protected function setUp(): void
    {
        $runtime = getenv('ARIX_DEV_TEST_RUNTIME');

        if ($runtime === false || $runtime === '') {
            $this->markTestSkipped('Set ARIX_DEV_TEST_RUNTIME for native server integration.');
        }

        $this->runtime = $runtime;
        $this->fixture = new NewApplicationFixture();
        $this->fixture->write('index.php', '<?php echo json_encode(["uri" => $_SERVER["REQUEST_URI"], "script" => $_SERVER["SCRIPT_NAME"], "method" => $_SERVER["REQUEST_METHOD"], "reload" => $_SERVER["FRANKENPHP_HOT_RELOAD"] ?? null]);');
        $this->fixture->write('app/bootstrap.php', '<?php');
        $this->fixture->write('core/autoload.php', '<?php');
        $this->fixture->write('styles/app.css', 'body{color:red}');
        $this->fixture->write('other.php', '<?php echo "EXPOSED";');
        $this->fixture->write('phpunit.xml', 'PRIVATE');
        $this->fixture->write('scripts/arix/index.js', 'export const value = 1;');
        $this->fixture->write('favicon.ico', 'ICON');
        $this->fixture->write('assets/Fonts/image.png', 'PRIVATE');
        $this->fixture->write('.frontend-backup-test/index.js', 'PRIVATE');

        foreach (['.env', '.env.local', '.env.production', '.env.bak', '.env.example', 'nested/.env'] as $path) {
            $this->fixture->write($path, 'PRIVATE');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    #[Test]
    public function it_preserves_front_controller_and_private_rules_with_live_reload(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);
        $options = DevOptions::fromInput([], $this->fixture->root);
        $configuration = new CaddyConfiguration(dirname(__DIR__, 3));
        $env = $configuration->environment($options, $port);
        $file = $configuration->prepare($options, $port, $this->fixture->root, $this->runtime);
        $generated = file_get_contents($file);
        $this->assertStringContainsString('fonts/**/*', $generated);
        $this->assertStringContainsString('media/**/*', $generated);
        $this->assertStringNotContainsString('assets/**/*', $generated);
        $server = new Process([$this->runtime, 'run', '--config', $file], $options->root, $env, timeout: null);
        $server->start();
        $multi = null;

        try {
            $deadline = microtime(true) + 10;
            do {
                [$status, $body] = $this->request($port, '/article?x=1');

                if ($status === 200) {
                    break;
                }

                usleep(50000);
            } while (microtime(true) < $deadline && $server->isRunning());

            $this->assertSame(200, $status, $server->getErrorOutput());
            $page = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('/article?x=1', $page['uri']);
            $this->assertSame('/index.php', $page['script']);
            $this->assertNotEmpty($page['reload']);

            foreach (['/app/bootstrap.php', '/core/autoload.php', '/phpunit.xml', '/.git/config', '/styles/missing.css', '/styles/file.php', '/assets/Fonts/image.png', '/.frontend-backup-test/index.js', '/.env', '/.env.local', '/.env.production', '/.env.bak', '/.env.example', '/nested/.env', '/%2eenv'] as $path) {
                $this->assertSame(403, $this->request($port, $path)[0], $path);
                $this->assertStringNotContainsString('PRIVATE', $this->request($port, $path)[1], $path);
            }

            $this->assertStringNotContainsString('EXPOSED', $this->request($port, '/other.php')[1]);
            $this->assertSame('body{color:red}', $this->request($port, '/styles/app.css')[1]);
            $this->assertSame('export const value = 1;', $this->request($port, '/scripts/arix/index.js')[1]);
            $this->assertSame('ICON', $this->request($port, '/favicon.ico')[1]);
            $this->assertSame('POST', json_decode($this->request($port, '/article', 'POST')[1], true)['method']);

            $events = '';
            $subscription = curl_init("http://127.0.0.1:{$port}{$page['reload']}");

            curl_setopt_array($subscription, [CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use (&$events): int {
                $events .= $data;

                return strlen($data);
            }, CURLOPT_TIMEOUT => 10]);

            $multi = curl_multi_init();
            curl_multi_add_handle($multi, $subscription);
            $deadline = microtime(true) + 8;

            do {
                curl_multi_exec($multi, $active);
                $this->fixture->write('styles/app.css', 'body{color:blue}' . microtime(true));
                usleep(250000);
            } while (! str_contains($events, 'app.css') && microtime(true) < $deadline);

            $this->assertStringContainsString('app.css', $events, 'Expected Mercure file-change event: ' . $server->getErrorOutput());
            curl_multi_remove_handle($multi, $subscription);
            curl_multi_close($multi);
            $multi = null;
        } finally {
            if ($multi !== null) {
                curl_multi_close($multi);
            }

            $server->stop(1);
        }
    }

    #[Test]
    public function it_serves_real_request_hosts_when_binding_to_a_wildcard_address(): void
    {
        $port = $this->freePort();
        $options = DevOptions::fromInput(['host' => '0.0.0.0', 'no-reload' => true], $this->fixture->root);
        $configuration = new CaddyConfiguration(dirname(__DIR__, 3));
        $file = $configuration->prepare($options, $port, $this->fixture->root, $this->runtime);
        $server = new Process([$this->runtime, 'run', '--config', $file], $options->root, $configuration->environment($options, $port), timeout: null);
        $server->start();

        try {
            $deadline = microtime(true) + 10;

            do {
                $body = $this->request($port, '/article?x=1')[1];
                $page = json_decode($body, true);

                if (is_array($page)) {
                    break;
                }
                usleep(50000);
            } while ($server->isRunning() && microtime(true) < $deadline);

            $this->assertSame('/article?x=1', $page['uri'] ?? null, 'Host: 127.0.0.1 must reach PHP when binding 0.0.0.0. ' . $server->getErrorOutput());
        } finally {
            $server->stop(1);
        }
    }

    #[Test]
    public function it_supports_root_paths_that_look_like_quotes_or_shell_syntax(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Quote characters are not valid Windows path characters.');
        }

        $name = 'q"uote $dollar and space';
        $root = $this->fixture->path($name);

        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php'] as $file) {
            $this->fixture->write($name . '/' . $file, '<?php echo json_encode(["uri" => $_SERVER["REQUEST_URI"]]);');
        }

        $port = $this->freePort();
        $options = DevOptions::fromInput(['path' => $root, 'no-reload' => true], $this->fixture->root);
        $configuration = new CaddyConfiguration(dirname(__DIR__, 3));
        $file = $configuration->prepare($options, $port, $this->fixture->root, $this->runtime);
        $server = new Process([$this->runtime, 'run', '--config', $file], $options->root, $configuration->environment($options, $port), timeout: null);
        $server->start();

        try {
            $deadline = microtime(true) + 10;

            do {
                $body = $this->request($port, '/weird')[1];
                $page = json_decode($body, true);

                if (is_array($page)) {
                    break;
                }

                usleep(50000);
            } while ($server->isRunning() && microtime(true) < $deadline);

            $this->assertSame('/weird', $page['uri'] ?? null, 'A root containing quotes, dollar signs or spaces must serve normally. ' . $server->getErrorOutput());
        } finally {
            $server->stop(1);
        }
    }

    private function freePort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        fclose($listener);

        return $port;
    }

    private function request(int $port, string $path, string $method = 'GET'): array
    {
        $curl = curl_init("http://127.0.0.1:{$port}{$path}");
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT_MS => 500]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return [$status, $body ?: ''];
    }
}
