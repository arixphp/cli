<?php

declare(strict_types=1);

namespace App\Commands;

use App\Development\DevOptions;
use App\Development\DevSession;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

class Dev extends Command
{
    protected $signature = 'dev
        {--path= : Application root, relative to invocation directory or absolute}
        {--host=127.0.0.1 : Host/IP to bind; network binding requires --no-reload}
        {--port=8181 : Preferred HTTP port}
        {--strict-port : Fail instead of trying subsequent ports}
        {--caddyfile= : Explicit development Caddyfile consuming ARIX_DEV variables}
        {--frankenphp= : Explicitly trusted FrankenPHP executable}
        {--no-reload : Disable Mercure and browser live reload}';

    protected $description = 'Serve an Arix application locally with managed FrankenPHP';

    public function handle(DevSession $session): int
    {
        try {
            $cwd = getcwd();

            if ($cwd === false) {
                throw new RuntimeException('Cannot determine invocation directory.');
            }

            $options = DevOptions::fromInput($this->options(), $cwd);
        } catch (RuntimeException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::INVALID;
        }

        $handlers = [];
        $async = null;

        if (extension_loaded('pcntl')) {
            $async = pcntl_async_signals(true);

            foreach ([SIGINT, SIGTERM] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);

                pcntl_signal($signal, static fn () => $session->cancel());
            }
        } elseif (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(static function () use ($session): bool {
                $session->cancel();

                return true;
            });
        }

        $output = $this->output->getOutput();
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            return $session->run($options, static function (string $stream, string $message) use ($output, $stderr): void {
                ($stream === Process::ERR ? $stderr : $output)->write($message, false, OutputInterface::OUTPUT_RAW);
            });
        } catch (Throwable $exception) {
            $this->error(OutputFormatter::escape('Cannot start development server: ' . $exception->getMessage()));

            return self::FAILURE;
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($async !== null) {
                pcntl_async_signals($async);
            }

            if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
                sapi_windows_set_ctrl_handler(null);
            }
        }
    }
}
