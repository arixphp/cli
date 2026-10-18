<?php

declare(strict_types=1);

namespace App\Commands\Make;

use App\Core\Update\ApplicationTarget;
use App\Make\GenerationPolicy;
use App\Make\Generator;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function getcwd;
use function is_string;

abstract class MakeCommand extends Command
{
    abstract protected function policy(): GenerationPolicy;

    public function handle(Generator $generator): int
    {
        $cwd = getcwd();
        $name = $this->argument('name');
        $path = $this->option('path');

        if ($cwd === false || ! is_string($name) || $name === '' || ! is_string($path) && $path !== null) {
            $this->error('Invalid generation arguments.');

            return self::INVALID;
        }

        try {
            $root = ApplicationTarget::resolve($path, $cwd);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        try {
            $result = $generator->generate($root, $this->policy(), $name, (bool) $this->option('force'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result->written ? "Generated {$result->path}." : "Already exists {$result->path}.");

        return self::SUCCESS;
    }
}
