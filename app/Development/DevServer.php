<?php

declare(strict_types=1);

namespace App\Development;

use RuntimeException;
use Symfony\Component\Process\Process;

class DevServer
{
    private bool $cancelled = false;

    public function __construct(private readonly CaddyConfiguration $configuration) {}

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function run(string $executable, DevOptions $options, callable $output): int
    {
        $session = new SessionFiles();
        $process = null;

        register_shutdown_function(static function () use (&$process, $session): void {
            $process?->stop(1);
            $session->cleanup();
        });

        try {

            foreach (PortCandidates::for($options->port, $options->strictPort) as $port) {
                if ($this->cancelled) {

                    return 130;
                }

                $code = $this->servePort($executable, $options, $output, $session, $port, $process);

                if ($code !== null) {
                    return $code;
                }
            }

            throw new RuntimeException('No available port in the requested range; use --port or omit --strict-port.');
        } finally {
            $process?->stop(1);
            $process = null;
            $session->cleanup();
        }
    }

    private function servePort(string $executable, DevOptions $options, callable $output, SessionFiles $session, int $port, Process|null &$process): int|null
    {
        $config = $this->configuration->prepare($options, $port, $session->directory, $executable);

        $process = new Process([$executable, 'run', '--config', $config], $options->root, $this->configuration->environment($options, $port), timeout: null);

        $process->start($output);

        if (! $this->waitUntilReady($options, $port, $process)) {
            return $this->handleNotReady($process);
        }

        $this->announce($options, $port, $executable, $output);

        $this->waitWhileRunning($process);

        if ($this->cancelled) {
            return 130;
        }

        if ($process->getExitCode() !== 0) {
            throw new RuntimeException('FrankenPHP terminated unexpectedly. ' . $process->getErrorOutput());
        }

        return 0;
    }

    private function waitUntilReady(DevOptions $options, int $port, Process $process): bool
    {
        $deadline = microtime(true) + 15;

        while (! $this->cancelled && $process->isRunning() && microtime(true) < $deadline) {
            if ($this->ready($options, $port)) {
                return true;
            }

            usleep(50000);
        }

        return false;
    }

    private function handleNotReady(Process $process): int|null
    {
        $error = $process->getErrorOutput();
        $process->stop(1);

        if (preg_match('/address already in use|EADDRINUSE|Only one usage of each socket address/i', $error) === 1) {
            return null;
        }

        throw new RuntimeException('FrankenPHP failed to start or did not become ready within 15 seconds. ' . $error);
    }

    private function announce(DevOptions $options, int $port, string $executable, callable $output): void
    {
        $output(Process::OUT, "Development server ready: {$options->address($port)}\n");
        $output(Process::OUT, 'Application: ' . $options->root . "\nRuntime: {$executable}\nConfiguration: " . ($options->caddyfile ?? 'internal') . "\n");
        $output(Process::OUT, $options->reload ? "Live reload enabled; your layout must include the local dev client. See docs/development-server.md.\n" : "Live reload disabled.\n");
    }

    private function waitWhileRunning(Process $process): void
    {
        while (! $this->cancelled && $process->isRunning()) {
            usleep(50000);
        }

    }

    private function ready(DevOptions $options, int $port): bool
    {
        $curl = curl_init($options->address($port) . $this->configuration->readinessPath());
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT_MS => 300, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($curl);

        return curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200 && $body === $this->configuration->token;
    }
}
