<?php

declare(strict_types=1);

namespace App\Development;

use Symfony\Component\Process\Process;

class DevSession
{
    public function __construct(private readonly RuntimeStore $runtime, private readonly DevServer $server) {}

    public function cancel(): void
    {
        $this->server->cancel();
    }

    public function run(DevOptions $options, callable $output): int
    {
        $executable = $this->runtime->resolve($options->frankenphp, $options->reload, static fn (string $message) => $output(Process::OUT, "{$message}\n"));

        return $this->server->run($executable, $options, $output);
    }
}
