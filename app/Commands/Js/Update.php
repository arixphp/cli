<?php

declare(strict_types=1);

namespace App\Commands\Js;

use App\Js\Update\JsDistribution;
use App\Js\Update\JsTarget;
use App\Js\Update\JsUpdater;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function is_string;

class Update extends Command
{
    protected $signature = 'js:update
        {--path= : Application root, relative to the invocation directory or absolute}
        {--dry-run : Validate and preview changes without writing files}
        {--yes : Confirm the update without an interactive prompt}
        {--force : Overwrite a locally modified scripts/arix.js}';

    protected $description = 'Safely replace the managed JavaScript framework of an Arix application';

    public function handle(JsUpdater $updater): int
    {
        try {
            $cwd = getcwd();

            if ($cwd === false) {
                throw new RuntimeException('Cannot determine invocation directory.');
            }

            $option = $this->option('path');
            $source = str_starts_with(base_path(), 'phar://') ? null : base_path('resources' . DIRECTORY_SEPARATOR . 'js');
            $application = JsTarget::resolve(is_string($option) ? $option : null, $cwd, $source);
            $distribution = $this->laravel->make(JsDistribution::class);
            $preview = $updater->preview($application, $distribution);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $installed = $preview['installedVersion'] ?? 'none';
        $this->line("Application: {$application}");
        $this->line("Distribution: arix.js {$preview['availableVersion']}");
        $this->line("installed: {$installed}");
        $this->line("action: {$preview['action']}");

        if ($this->option('dry-run')) {
            $this->info('Dry run; no files written.');

            return self::SUCCESS;
        }

        if ($preview['action'] === 'up-to-date') {
            $this->info('No changes; the JavaScript framework is already up to date.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        if ($preview['action'] === 'modified' && ! $force) {
            $this->error('The installed scripts/arix.js was modified locally; re-run with --force to overwrite it.');

            return self::INVALID;
        }

        $this->warn('Browsers may cache scripts/arix.js; bump the module query string in the layout when needed.');

        if (! $this->option('yes')) {
            if (! $this->input->isInteractive()) {
                $this->error('Noninteractive updates require --yes after arranging maintenance.');

                return self::INVALID;
            }

            if (! $this->confirm("Replace scripts/arix.js {$installed} with {$preview['availableVersion']}?")) {
                $this->info('Cancelled; no files written.');

                return self::SUCCESS;
            }
        }

        try {
            $backup = $updater->update($application, $distribution, $preview['installedHash'], $force);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('JavaScript framework integrity verified.');

        if ($backup !== null) {
            $this->line("Backup retained: {$backup}");
        }

        return self::SUCCESS;
    }
}
