<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Core\Update\CoreFiles;
use RuntimeException;
use Throwable;

use function in_array;
use function is_string;

class FrontendTransaction
{
    /**
     * Caller holds FrontendLock. Journal paths are never executed as recovery instructions.
     *
     * @param  list<ManagedDistribution>  $distributions
     * @param  array<string, array<string, string>|null>  $expected
     */
    public function install(string $application, array $distributions, array $expected): string
    {
        FrontendFiles::assertNoPendingOperation($application);
        $bodies = $this->prepareBodies($application, $distributions, $expected);
        [$stage, $backup, $journal, $token] = $this->createWorkspace($application);
        $moved = [];
        $installed = [];

        try {
            $this->writeJournal($journal, $token, $bodies);
            $this->stage($stage, $bodies);
            $this->installDirectories($application, $stage, $backup, $bodies, $expected, $moved, $installed);
        } catch (Throwable $exception) {
            $this->recover($application, $stage, $backup, $journal, $bodies, $moved, $installed, $exception);
        }

        $this->cleanStage($stage, $bodies);

        if (! unlink($journal)) {
            throw new RuntimeException('Frontend installed but journal cleanup failed; retain recovery evidence.');
        }

        return $backup;
    }

    /** @param list<ManagedDistribution> $distributions
     * @param array<string, array<string, string>|null> $expected
     * @return array<string, array<string, string>> */
    private function prepareBodies(string $application, array $distributions, array $expected): array
    {
        $bodies = [];

        foreach ($distributions as $distribution) {
            $directory = $distribution->directory();
            $inventory = $distribution->inventory();
            $allowed = $this->allowedFiles($directory);

            if (isset($bodies[$directory]) || array_diff(array_keys($inventory), $allowed) !== [] || array_diff($allowed, array_keys($inventory)) !== []) {
                throw new RuntimeException('Invalid managed frontend inventory.');
            }

            $bodies[$directory] = $this->verifiedBodies($distribution, $inventory);
            $this->assertExpected($application, $directory, array_keys($inventory), $expected[$directory] ?? null);

            if (! is_dir(dirname(FrontendFiles::target($application, $directory)))) {
                throw new RuntimeException('Application public directories are missing.');
            }
        }

        return $bodies;
    }

    /** @return list<string> */
    private function allowedFiles(string $directory): array
    {
        return match ($directory) {
            'styles/arix' => ['base.css', 'components.css', 'tokens.css', 'utilities.css'],
            'scripts/arix' => ['index.js', 'dev-reload.js'],
            default => throw new RuntimeException('Invalid managed frontend directory.'),
        };
    }

    /** @param array<string, string> $inventory
     * @return array<string, string> */
    private function verifiedBodies(ManagedDistribution $distribution, array $inventory): array
    {
        $bodies = [];

        foreach ($inventory as $file => $hash) {
            $body = $distribution->contents($file);

            if (! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1 || hash('sha256', $body) !== $hash) {
                throw new RuntimeException('Managed frontend release integrity mismatch.');
            }

            $bodies[$file] = $body;
        }

        return $bodies;
    }

    /** @return array{string, string, string, string} */
    private function createWorkspace(string $application): array
    {
        $token = bin2hex(random_bytes(12));
        $stage = $application . DIRECTORY_SEPARATOR . ".frontend-stage-{$token}";
        $backup = $application . DIRECTORY_SEPARATOR . ".frontend-backup-{$token}";

        if (! mkdir($stage, 0700) || ! mkdir($backup, 0700)) {
            throw new RuntimeException('Cannot create private frontend staging; retain operation evidence.');
        }

        return [$stage, $backup, $application . DIRECTORY_SEPARATOR . '.frontend-update.json', $token];
    }

    /** @param array<string, array<string, string>> $bodies */
    private function writeJournal(string $journal, string $token, array $bodies): void
    {
        $handle = fopen($journal, 'x');

        if ($handle === false) {
            throw new RuntimeException('Cannot create frontend journal.');
        }

        try {
            if (! chmod($journal, 0600)) {
                throw new RuntimeException('Cannot secure frontend journal.');
            }

            $bytes = json_encode(['format' => 1, 'token' => $token, 'directories' => array_keys($bodies)], JSON_THROW_ON_ERROR);

            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('Cannot write frontend journal.');
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, array<string, string>> $bodies */
    private function stage(string $stage, array $bodies): void
    {
        foreach ($bodies as $directory => $files) {
            $staged = $stage . DIRECTORY_SEPARATOR . $this->publicDirectory($directory);

            if (! mkdir($staged, 0755)) {
                throw new RuntimeException('Cannot stage frontend directory.');
            }

            foreach ($files as $file => $body) {
                CoreFiles::write($staged . DIRECTORY_SEPARATOR . $file, $body);
            }
        }
    }

    /** @param array<string, array<string, string>> $bodies
     * @param array<string, array<string, string>|null> $expected
     * @param list<string> $moved
     * @param list<string> $installed */
    private function installDirectories(string $application, string $stage, string $backup, array $bodies, array $expected, array &$moved, array &$installed): void
    {
        foreach ($bodies as $directory => $files) {
            $name = $this->publicDirectory($directory);
            $target = FrontendFiles::target($application, $directory);
            $this->assertExpected($application, $directory, array_keys($files), $expected[$directory] ?? null);

            if (is_dir($target)) {
                $this->move($target, $backup . DIRECTORY_SEPARATOR . $name);
                $moved[] = $directory;
            }

            $this->move($stage . DIRECTORY_SEPARATOR . $name, $target);
            $installed[] = $directory;
            $this->assertExpected($application, $directory, array_keys($files), $this->hashes($files));
        }
    }

    /** @param array<string, array<string, string>> $bodies
     * @param list<string> $moved
     * @param list<string> $installed */
    private function recover(
        string $application,
        string $stage,
        string $backup,
        string $journal,
        array $bodies,
        array $moved,
        array $installed,
        Throwable $exception
    ): never {
        try {
            foreach (array_reverse(array_keys($bodies)) as $directory) {
                $name = $this->publicDirectory($directory);
                $target = FrontendFiles::target($application, $directory);

                if (in_array($directory, $installed, true)) {
                    $this->assertExpected($application, $directory, array_keys($bodies[$directory]), $this->hashes($bodies[$directory]));
                    $this->move($target, $stage . DIRECTORY_SEPARATOR . $name);
                }

                if (in_array($directory, $moved, true)) {
                    $this->move($backup . DIRECTORY_SEPARATOR . $name, $target);
                }
            }

            $this->cleanStage($stage, $bodies);

            if (! rmdir($backup) || ! unlink($journal)) {
                throw new RuntimeException('Cannot remove rolled-back frontend operation metadata.');
            }
        } catch (Throwable $recovery) {
            throw new RuntimeException("Frontend recovery required. Retain {$journal}, {$stage} and {$backup}. {$recovery->getMessage()}", previous: $exception);
        }

        throw new RuntimeException('Frontend operation failed; previous managed directories restored.', previous: $exception);
    }

    /** @param array<string, string> $files
     * @return array<string, string> */
    private function hashes(array $files): array
    {
        return array_map(static fn (string $body): string => hash('sha256', $body), $files);
    }

    private function publicDirectory(string $directory): string
    {
        return explode('/', $directory)[0];
    }

    protected function move(string $from, string $to): void
    {
        CoreFiles::assertUnlinked($from);
        CoreFiles::assertUnlinked($to);

        if (file_exists($to) || ! rename($from, $to)) {
            throw new RuntimeException('Cannot replace managed frontend directory.');
        }
    }

    private function assertExpected(string $application, string $directory, array $allowed, array|null $expected): void
    {
        $actual = FrontendFiles::inventory($application, $directory, $allowed);

        if ($expected !== null) {
            ksort($expected);
        }

        if ($actual !== $expected) {
            throw new RuntimeException('Managed frontend changed since preview; retry the operation.');
        }
    }

    private function cleanStage(string $stage, array $bodies): void
    {
        CoreFiles::assertUnlinked($stage);

        foreach ($bodies as $directory => $files) {
            $path = $stage . DIRECTORY_SEPARATOR . explode('/', $directory)[0];
            CoreFiles::assertUnlinked($path);

            if (! is_dir($path)) {
                continue;
            }

            foreach (array_keys($files) as $file) {
                $entry = $path . DIRECTORY_SEPARATOR . $file;
                CoreFiles::assertUnlinked($entry);

                if (is_file($entry) && ! unlink($entry)) {
                    throw new RuntimeException('Cannot clean frontend staging file.');
                }
            }

            if (! rmdir($path)) {
                throw new RuntimeException('Unexpected files in frontend staging; manual inspection required.');
            }
        }

        if (! rmdir($stage)) {
            throw new RuntimeException('Cannot clean frontend staging.');
        }
    }
}
