<?php

declare(strict_types=1);

namespace App\Commands\Css;

use App\Css\Update\CssDistribution;
use App\Css\Update\CssTarget;
use App\Css\Update\CssUpdater;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function count;
use function is_string;

class Update extends Command
{
    protected $signature = 'css:update
        {--path= : Application root, relative to the invocation directory or absolute}
        {--dry-run : Validate and preview changes without writing files}
        {--yes : Confirm the update without an interactive prompt}
        {--force : Overwrite locally modified styles/*.css files}';

    protected $description = 'Safely replace the managed styles of an Arix application';

    public function handle(CssUpdater $updater): int
    {
        try {
            $cwd = getcwd();

            if ($cwd === false) {
                throw new RuntimeException('Cannot determine invocation directory.');
            }

            $option = $this->option('path');
            $source = str_starts_with(base_path(), 'phar://') ? null : base_path('resources' . DIRECTORY_SEPARATOR . 'css');
            $application = CssTarget::resolve(is_string($option) ? $option : null, $cwd, $source);
            $distribution = $this->laravel->make(CssDistribution::class);
            $preview = $updater->preview($application, $distribution);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $installed = $preview['installedRelease'] ?? ($preview['action'] === 'install' ? 'none' : 'modified');
        $this->line("Application: {$application}");
        $this->line("Distribution: css {$preview['availableVersion']} (" . count(CssDistribution::FILES) . ' files)');
        $this->line("installed: {$installed}");
        $this->line("action: {$preview['action']}");

        if ($this->option('dry-run')) {
            $this->info('Dry run; no files written.');

            return self::SUCCESS;
        }

        if ($preview['action'] === 'up-to-date') {
            $this->info('No changes; the managed styles are already up to date.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        if ($preview['action'] === 'modified' && ! $force) {
            $this->error('The installed styles/*.css files were modified locally; re-run with --force to overwrite them.');

            return self::INVALID;
        }

        $this->warn('Browsers may cache styles/*.css; bump the stylesheet query string in the layout when needed.');

        if (! $this->option('yes')) {
            if (! $this->input->isInteractive()) {
                $this->error('Noninteractive updates require --yes after arranging maintenance.');

                return self::INVALID;
            }

            if (! $this->confirm("Replace managed styles {$installed} with {$preview['availableVersion']}?")) {
                $this->info('Cancelled; no files written.');

                return self::SUCCESS;
            }
        }

        try {
            $backup = $updater->update($application, $distribution, $preview['installedHashes'], $force);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Managed styles integrity verified.');

        if ($backup !== null) {
            $this->line("Backup retained: {$backup}");
        }

        return self::SUCCESS;
    }
}
