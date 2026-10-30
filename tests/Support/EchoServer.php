<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

use function microtime;
use function str_contains;
use function usleep;

class EchoServer
{
    private readonly Process $process;

    private readonly int $port;

    public function __construct()
    {
        $this->port = self::freePort();
        $this->process = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", self::router()],
            null,
            ['XDEBUG_MODE' => 'off'],
            timeout: null,
        );
        $this->process->start();
        $this->waitUntilReady();
    }

    public function url(string $path = '/'): string
    {
        return "http://127.0.0.1:{$this->port}{$path}";
    }

    public function host(): string
    {
        return '127.0.0.1';
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }
    }

    private static function router(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'EchoServer' . DIRECTORY_SEPARATOR . 'router.php';
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);

        fclose($socket);

        $position = strrpos($name, ':');

        return (int) substr($name, $position + 1);
    }

    private function waitUntilReady(): void
    {
        $deadline = microtime(true) + 10;

        do {
            if (str_contains($this->process->getErrorOutput(), 'Development Server')) {
                return;
            }

            if (! $this->process->isRunning()) {
                break;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        $output = $this->process->getErrorOutput() . $this->process->getOutput();

        throw new RuntimeException("The echo server did not start: {$output}");
    }
}
