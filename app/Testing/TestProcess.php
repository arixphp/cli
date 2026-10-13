<?php

declare(strict_types=1);

namespace App\Testing;

use Symfony\Component\Process\Process;

class TestProcess
{
    /** @param callable(string, string): void $output */
    public function run(string $application, string $entry, bool $decorated, callable $output, bool $selfExecutable = false): int
    {
        $command = $selfExecutable ? [$entry] : [PHP_BINARY, $entry];
        $process = new Process([...$command, '--arix-tests-worker', $application, $decorated ? 'always' : 'never'], $application);
        $process->setTimeout(null);

        return $process->run($output);
    }
}
