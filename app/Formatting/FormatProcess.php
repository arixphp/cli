<?php

declare(strict_types=1);

namespace App\Formatting;

use Symfony\Component\Process\Process;

class FormatProcess
{
    /**
     * @param  callable(string, string): void  $output
     */
    public function run(
        string $application,
        string $entry,
        string $config,
        bool $test,
        callable $output,
        bool $selfExecutable = false
    ): int {
        $command = $selfExecutable ? [$entry] : [PHP_BINARY, $entry];
        $process = new Process([...$command, '--arix-format-worker', $application, $config, $test ? 'check' : 'fix'], $application);
        $process->setTimeout(null);

        return $process->run($output);
    }
}
