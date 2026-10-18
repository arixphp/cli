<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\CaddyConfiguration;
use App\Development\DevOptions;
use App\Development\DevServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\NewApplicationFixture;

class DevServerTest extends TestCase
{
    private NewApplicationFixture $fixture;

    private string $runtime;

    protected function setUp(): void
    {
        $runtime = getenv('ARIX_DEV_TEST_RUNTIME');

        if ($runtime === false || $runtime === '') {
            $this->markTestSkipped('Set ARIX_DEV_TEST_RUNTIME for owned-process integration.');
        }

        $this->runtime = $runtime;
        $this->fixture = new NewApplicationFixture();

        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php'] as $file) {
            $this->fixture->write($file, '<?php http_response_code(500); echo "Application error";');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    #[Test]
    public function it_selects_an_available_port_and_stops_only_its_child(): void
    {
        $occupied = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($occupied, false), ':'), 1);
        $options = DevOptions::fromInput(['port' => (string) $port, 'no-reload' => true], $this->fixture->root);
        $server = new DevServer(new CaddyConfiguration(dirname(__DIR__, 3)));
        $output = '';

        try {
            $code = $server->run($this->runtime, $options, static function (string $stream, string $message) use (&$output, $server): void {
                $output .= $message;

                if (str_contains($message, 'Development server ready')) {
                    $server->cancel();
                }
            });

            $this->assertSame(130, $code);
            $this->assertStringNotContainsString("ready: http://127.0.0.1:{$port}\n", $output);
            $this->assertStringContainsString('Development server ready', $output);
            $this->assertIsResource($occupied);
        } finally {
            fclose($occupied);
        }
    }

    #[Test]
    public function it_fails_strict_ports_without_touching_another_listener(): void
    {
        $occupied = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($occupied, false), ':'), 1);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('available port');
            new DevServer(new CaddyConfiguration(dirname(__DIR__, 3)))->run($this->runtime, DevOptions::fromInput(['port' => $port, 'strict-port' => true, 'no-reload' => true], $this->fixture->root), static function (): void {});
        } finally {
            fclose($occupied);
        }
    }
}
