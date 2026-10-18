<?php

declare(strict_types=1);

namespace App\Commands;

use App\Formatting\Formatter;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

use function is_string;

class Format extends Command
{
    protected $signature = 'format
        {--path= : Application root, relative to invocation directory or absolute}
        {--config= : Optional path to a user format rules configuration file}';

    protected $description = 'Format application PHP code using Pint.';

    public function handle(Formatter $formatter): int
    {
        try {
            $cwd = getcwd();

            if ($cwd === false) {
                throw new RuntimeException('Cannot determine invocation directory.');
            }

            $path = $this->option('path');
            $config = $this->option('config');
            $output = $this->output;

            if (! $output instanceof OutputInterface) {
                throw new RuntimeException('Console output is unavailable.');
            }

            return $formatter->format(is_string($path) ? $path : null, is_string($config) ? $config : null, $cwd, $output);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
    }
}
