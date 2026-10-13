<?php

declare(strict_types=1);

namespace App\Commands;

use App\Installation\ApplicationInstaller;
use App\Installation\InstallationTarget;
use App\Installation\PlatformRequirements;
use App\Installation\RecoveryFailed;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

use function is_string;

class CreateNewApp extends Command
{
    protected $signature = 'new
        {name? : Application directory name}
        {directory=. : Parent directory where the application will be created}';

    protected $description = 'Create a new Arix application from the official skeleton';

    public function handle(ApplicationInstaller $installer, PlatformRequirements $platform): int
    {
        try {
            $platform->verify();
        } catch (RuntimeException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        }

        $cwd = getcwd();

        if ($cwd === false) {
            $this->error('Cannot determine invocation directory.');

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $directory = $this->argument('directory');

        if (! is_string($name) || $name === '' || ! is_string($directory)) {
            $this->error('Invalid arguments. Provide an application name and a parent directory.');

            return self::INVALID;
        }

        try {
            $target = InstallationTarget::resolve($name, $directory, $cwd);
        } catch (InvalidArgumentException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::INVALID;
        }

        try {
            $commit = $installer->install($target);
        } catch (RecoveryFailed $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        } catch (InvalidArgumentException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error(OutputFormatter::escape($exception->getMessage()));

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error(OutputFormatter::escape("Unexpected installation failure: {$exception->getMessage()}"));

            return self::FAILURE;
        }

        $path = OutputFormatter::escape($target->path);
        $sha = OutputFormatter::escape($commit);
        $this->info("Application created at {$path}.");
        $this->line("Skeleton arixphp/app commit {$sha}.");
        $this->line('Server requirements: PHP >= 8.4.1 with ext-mbstring. These describe the hosting runtime, not this CLI.');

        return self::SUCCESS;
    }
}
