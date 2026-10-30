<?php

declare(strict_types=1);

namespace App\Commands;

use App\Testing\ApplicationTarget;
use App\Testing\TestProcess;
use LaravelZero\Framework\Commands\Command;
use Phar;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

use function is_string;

class Tests extends Command
{
    protected $signature = 'tests {--path= : Application root, relative to the invocation directory or absolute}';

    protected $description = 'Run application tests using the Pest runner bundled with the CLI';

    public function handle(TestProcess $runner): int
    {
        try {
            $cwd = getcwd();

            if ($cwd === false) {
                throw new RuntimeException('Cannot determine invocation directory.');
            }

            $path = $this->option('path');
            $application = ApplicationTarget::resolve(is_string($path) ? $path : null, $cwd);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $selfExecutable = PHP_SAPI === 'micro';
        $entry = $selfExecutable ? micro_get_self_filename() : (Phar::running(false) ?: base_path('arix'));
        $output = $this->output->getOutput();
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {

            return $runner->run($application, $entry, $output->isDecorated(), static function (string $type, string $buffer) use ($output, $stderr): void {
                ($type === Process::ERR ? $stderr : $output)->write($buffer, false);
            }, $selfExecutable);
        } catch (RuntimeException $exception) {
            $this->error("Cannot launch application tests: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            (new Filesystem())->remove($application . DIRECTORY_SEPARATOR . '--record-test-run-history');
        }
    }
}
