<?php

declare(strict_types=1);

namespace App\Commands\Core;

use App\Core\Update\ApplicationTarget;
use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use App\Core\Update\CoreUpdater;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function count;
use function is_string;

class Update extends Command
{
    protected $signature = 'core:update
        {--path= : Application root, relative to the invocation directory or absolute}
        {--dry-run : Validate and preview changes without writing files}
        {--yes : Confirm an offline update without an interactive prompt}';

    protected $description = 'Safely replace the managed core of a compatible Arix application';

    public function handle(CoreInspector $inspector, CoreUpdater $updater): int
    {
        try {
            $cwd = getcwd();

            if ($cwd === false) {
                throw new RuntimeException('Cannot determine invocation directory.');
            }

            $option = $this->option('path');
            $source = str_starts_with(base_path(), 'phar://') ? null : base_path('core');
            $application = ApplicationTarget::resolve(is_string($option) ? $option : null, $cwd, $source);
            $distribution = $this->laravel->make(CoreDistribution::class);
            $preview = $inspector->inspect($application, $distribution);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $this->line("Application: {$application}");
        $this->line("Distribution: {$distribution->manifest['id']}");

        foreach (['added', 'modified', 'removed'] as $kind) {
            $total = count($preview[$kind]);
            $this->line("{$kind}: {$total}");

            foreach ($preview[$kind] as $file) {
                $this->line("  {$file}");
            }
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run; no files written.');

            return self::SUCCESS;
        }

        if ($preview['installed']['id'] === $distribution->manifest['id']) {
            $this->info('No changes; core is already up to date.');

            return self::SUCCESS;
        }

        $this->warn('Offline update only. Stop HTTP traffic first; refresh application caches and OPcache before reopening.');

        if (! $this->option('yes')) {
            if (! $this->input->isInteractive()) {
                $this->error('Noninteractive updates require --yes after arranging maintenance.');

                return self::INVALID;
            }

            if (! $this->confirm('Traffic is stopped and caches will be refreshed. Update core?')) {
                $this->info('Cancelled; no files written.');

                return self::SUCCESS;
            }
        }

        try {
            $backup = $updater->update($application, $distribution, $preview['installed']['id']);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Core integrity verified. HTTP application health has not been tested.');

        if ($backup !== null) {
            $this->line("Backup retained: {$backup}");
        }

        foreach ($updater->cleanupWarnings() as $warning) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
