<?php

declare(strict_types=1);

namespace App\Core\Update;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

use function is_resource;

class CoreUpdater
{
    /** @var list<string> */
    private array $cleanupWarnings = [];

    /** @return list<string> */
    public function cleanupWarnings(): array
    {

        return $this->cleanupWarnings;
    }

    public function update(string $application, CoreDistribution $distribution, string $expectedId): string|null
    {
        $this->cleanupWarnings = [];
        $application = ApplicationTarget::resolve($application, $application);
        $lockPath = $application . DIRECTORY_SEPARATOR . '.core-update.lock';

        CoreFiles::assertUnlinked($lockPath);

        if (file_exists($lockPath) && ! is_file($lockPath)) {
            throw new RuntimeException('Invalid update lock file.');
        }

        $lock = @fopen($lockPath, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Cannot acquire core update lock.');
        }

        try {
            if (! chmod($lockPath, 0600)) {
                throw new RuntimeException('Cannot secure core update lock.');
            }

            $inspector = new CoreInspector();
            $preview = $inspector->inspect($application, $distribution);

            if ($preview['installed']['id'] !== $expectedId) {
                throw new RuntimeException('Installed core changed since preview; run the command again.');
            }

            if ($expectedId === $distribution->manifest['id']) {

                return null;
            }

            $backup = $this->install($application, $distribution, $preview['installed'], $inspector);

            // Installation is committed. Cleanup must never trigger rollback.
            $this->pruneBackups($application, $backup, $inspector);

            return $backup;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function writeFile(string $path, string $contents): void
    {
        CoreFiles::write($path, $contents);
    }

    protected function move(string $from, string $to): void
    {
        CoreFiles::assertUnlinked($from);
        CoreFiles::assertUnlinked($to);

        if (file_exists($to) || ! @rename($from, $to)) {
            throw new RuntimeException("Cannot move {$from} to {$to}");
        }
    }

    private function install(string $application, CoreDistribution $distribution, array $previous, CoreInspector $inspector): string
    {
        $token = bin2hex(random_bytes(12));
        $core = $application . DIRECTORY_SEPARATOR . 'core';
        $stage = $application . DIRECTORY_SEPARATOR . ".core-stage-{$token}";
        $backup = $application . DIRECTORY_SEPARATOR . ".core-backup-{$token}";
        $journal = $application . DIRECTORY_SEPARATOR . '.core-update.json';
        $temporaryJournal = "{$journal}.{$token}";
        $backedUp = false;
        $installed = false;
        $record = ['format' => 1, 'stage' => $stage, 'backup' => $backup, 'previous' => $previous['id'], 'next' => $distribution->manifest['id']];

        try {
            $this->journal($journal, $temporaryJournal, $record, 'staging');
            $this->directory($stage);

            foreach ($distribution->manifest['files'] as $path => $hash) {
                $destination = CoreFiles::local($stage, $path);
                $this->directory(dirname($destination));
                $this->writeFile($destination, $distribution->contents($path));
            }

            $this->writeFile($stage . DIRECTORY_SEPARATOR . 'distribution.json', json_encode($distribution->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

            if ($inspector->installedManifest($stage)['id'] !== $distribution->manifest['id']) {
                throw new RuntimeException('Staged core does not match distribution.');
            }

            ApplicationTarget::resolve($application, $application);

            if ($inspector->installedManifest($core)['id'] !== $previous['id']) {
                throw new RuntimeException('Installed core changed during staging.');
            }

            $this->journal($journal, $temporaryJournal, $record, 'backing-up');
            $this->move($core, $backup);
            $backedUp = true;
            $this->journal($journal, $temporaryJournal, $record, 'installing');
            $this->move($stage, $core);
            $installed = true;
            $this->journal($journal, $temporaryJournal, $record, 'verifying');

            if ($inspector->installedManifest($core)['id'] !== $distribution->manifest['id']) {
                throw new RuntimeException('Installed core does not match distribution.');
            }

            $this->unlink($journal);

            return $backup;
        } catch (Throwable $exception) {
            try {
                if ($installed) {
                    $this->move($core, $stage);
                }

                if ($backedUp) {
                    $this->move($backup, $core);

                    if ($inspector->installedManifest($core)['id'] !== $previous['id']) {
                        throw new RuntimeException('Restored core integrity check failed.');
                    }
                }

                $this->removeTree($stage);

                foreach ([$temporaryJournal, $journal] as $file) {
                    if (file_exists($file)) {
                        $this->unlink($file);
                    }
                }
            } catch (Throwable $recoveryException) {
                throw new RuntimeException("Update failed and requires manual recovery. Keep {$journal}, {$stage} and {$backup}. Recovery error: {$recoveryException->getMessage()}", previous: $exception);
            }

            throw new RuntimeException("Core update failed; original core retained/restored: {$exception->getMessage()}", previous: $exception);
        }
    }

    private function journal(string $path, string $temporary, array $record, string $state): void
    {
        CoreFiles::assertUnlinked($path);
        $this->writeFile($temporary, json_encode([...$record, 'state' => $state], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        if (! chmod($temporary, 0600) || ! @rename($temporary, $path)) {
            throw new RuntimeException('Cannot record core update state.');
        }
    }

    private function directory(string $path): void
    {
        CoreFiles::assertUnlinked($path);

        if (! is_dir($path) && ! @mkdir($path, 0755, true)) {
            throw new RuntimeException("Cannot create update directory: {$path}");
        }

        if (! chmod($path, 0755)) {
            throw new RuntimeException("Cannot set directory permissions: {$path}");
        }
    }

    private function pruneBackups(string $application, string $retained, CoreInspector $inspector): void
    {
        try {
            $entries = @scandir($application);

            if ($entries === false) {
                throw new RuntimeException('Cannot list old core backups.');
            }

            foreach ($entries as $entry) {
                if (! preg_match('/^\.core-backup-[a-f0-9]{24}$/D', $entry)) {
                    continue;
                }

                $backup = $application . DIRECTORY_SEPARATOR . $entry;

                if ($backup === $retained) {
                    continue;
                }

                try {
                    $inspector->installedManifest($backup);
                    $this->removeTree($backup);
                } catch (Throwable $exception) {
                    $this->cleanupWarnings[] = "Could not clean old core backup {$backup}: {$exception->getMessage()}";
                }
            }
        } catch (Throwable $exception) {
            $this->cleanupWarnings[] = "Could not clean old core backups: {$exception->getMessage()}";
        }
    }

    protected function removeTree(string $stage): void
    {
        CoreFiles::assertUnlinked($stage);

        if (! file_exists($stage)) {

            return;
        }

        // Validate the entire owned tree before deleting any of it.
        CoreFiles::inventory($stage);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            CoreFiles::assertUnlinked($entry->getPathname());

            if ($entry->isDir()) {
                if (! @rmdir($entry->getPathname())) {
                    throw new RuntimeException("Cannot remove update directory: {$stage}");
                }
            } else {
                $this->unlink($entry->getPathname());
            }
        }

        if (! @rmdir($stage)) {
            throw new RuntimeException("Cannot remove update directory: {$stage}");
        }
    }

    private function unlink(string $path): void
    {
        CoreFiles::assertUnlinked($path);

        if (! @unlink($path)) {
            throw new RuntimeException("Cannot remove update file: {$path}");
        }
    }
}
