<?php

declare(strict_types=1);

namespace App\Frontend;

use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function is_string;

abstract class FrontendUpdateCommand extends Command
{
    abstract protected function distribution(): ManagedDistribution;

    public function handle(ManagedFrontendUpdater $updater): int
    {

        try {
            $path = $this->option('path');
            $cwd = getcwd();

            if ($cwd === false) {
                throw new RuntimeException('Cannot determine invocation directory.');
            }

            $application = FrontendFiles::application(is_string($path) ? $path : $cwd);
            $distribution = $this->distribution();
            $preview = $updater->preview($application, $distribution);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $installed = $preview['installedVersion'] ?? 'modified';
        $available = $preview['availableVersion'];
        $kind = str_starts_with($distribution->directory(), 'styles/') ? 'styles' : 'scripts';
        $this->line("Application: {$application}");
        $this->line("Distribution: {$available}");
        $this->line("installed: {$installed}");
        $this->line("action: {$preview['action']}");

        if ($this->option('dry-run')) {
            $this->info('Dry run; no files written.');

            return self::SUCCESS;
        }

        if ($preview['action'] === 'up-to-date') {
            $this->info('No changes; managed frontend is already up to date.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        if ($preview['action'] === 'modified' && ! $force) {
            $this->error('Managed files were modified locally; use --force only to replace managed files.');

            return self::INVALID;
        }

        $this->warn('Imported CSS/JS revalidates independently. Changing the entrypoint query alone does not invalidate imports.');

        if (! $this->option('yes')) {

            if (! $this->input->isInteractive()) {
                $this->error('Noninteractive updates require --yes after arranging maintenance.');

                return self::INVALID;
            }

            if (! $this->confirm("Replace managed {$kind} {$installed} with {$available}?")) {
                $this->info('Cancelled; no files written.');

                return self::SUCCESS;
            }
        }

        try {
            $backup = $updater->update($application, $distribution, $preview, $force);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Managed frontend integrity verified.');

        if ($backup !== null) {
            $this->line("Backup retained: {$backup}");
        }

        return self::SUCCESS;
    }
}
