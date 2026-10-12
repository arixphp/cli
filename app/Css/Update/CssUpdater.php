<?php

declare(strict_types=1);

namespace App\Css\Update;

use App\Core\Update\CoreFiles;
use RuntimeException;
use Throwable;

use function is_resource;
use function is_string;

class CssUpdater
{
    /** @return array{installedRelease: string|null, installedHashes: array<string, string|null>, availableVersion: string, action: string} */
    public function preview(string $application, CssDistribution $distribution): array
    {
        $installed = $this->installed($application);
        $release = $distribution->releaseOf($installed);

        if ($release !== null) {
            $action = $release === $distribution->version ? 'up-to-date' : 'update';

            return ['installedRelease' => $release, 'installedHashes' => $installed, 'availableVersion' => $distribution->version, 'action' => $action];
        }

        foreach ($installed as $hash) {
            if ($hash !== null) {

                return ['installedRelease' => null, 'installedHashes' => $installed, 'availableVersion' => $distribution->version, 'action' => 'modified'];
            }
        }

        return ['installedRelease' => null, 'installedHashes' => $installed, 'availableVersion' => $distribution->version, 'action' => 'install'];
    }

    /** @param array<string, string|null> $expectedHashes */
    public function update(string $application, CssDistribution $distribution, array $expectedHashes, bool $force = false): string|null
    {
        $application = CssTarget::resolve($application, $application);
        $lockPath = $application . DIRECTORY_SEPARATOR . '.css-update.lock';

        CoreFiles::assertUnlinked($lockPath);

        if (file_exists($lockPath) && ! is_file($lockPath)) {
            throw new RuntimeException('Invalid update lock file.');
        }

        $lock = @fopen($lockPath, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Cannot acquire styles update lock.');
        }

        $completed = false;

        try {
            if (! chmod($lockPath, 0600)) {
                throw new RuntimeException('Cannot secure styles update lock.');
            }

            $preview = $this->preview($application, $distribution);

            if ($expectedHashes !== $preview['installedHashes']) {
                throw new RuntimeException('Installed styles changed since preview; run the command again.');
            }

            if ($preview['action'] === 'up-to-date') {
                $completed = true;

                return null;
            }

            if ($preview['action'] === 'modified' && ! $force) {
                throw new RuntimeException('Installed styles/*.css files were modified locally; re-run with --force to overwrite them.');
            }

            $backup = $this->install($application, $distribution);
            $completed = true;

            return $backup;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);

            if ($completed) {
                @unlink($lockPath);
            }
        }
    }

    protected function writeFile(string $path, string $contents): void
    {
        CoreFiles::write($path, $contents);
    }

    /** @return array<string, string|null> */
    private function installed(string $application): array
    {
        $styles = $application . DIRECTORY_SEPARATOR . 'styles';
        $installed = [];

        foreach (CssDistribution::FILES as $file) {
            $path = $styles . DIRECTORY_SEPARATOR . $file;
            CoreFiles::assertUnlinked($path);

            if (! is_file($path)) {
                $installed[$file] = null;

                continue;
            }

            $hash = hash_file('sha256', $path);

            if (! is_string($hash)) {
                throw new RuntimeException("Cannot hash the installed styles: {$file}");
            }

            $installed[$file] = $hash;
        }

        return $installed;
    }

    private function install(string $application, CssDistribution $distribution): string
    {
        $styles = $application . DIRECTORY_SEPARATOR . 'styles';
        $token = bin2hex(random_bytes(12));
        $backup = $application . DIRECTORY_SEPARATOR . ".styles-backup-{$token}";
        $backedUp = [];
        $staged = [];

        try {
            CoreFiles::assertUnlinked($backup);

            if (! mkdir($backup, 0755, true) || ! chmod($backup, 0755)) {
                throw new RuntimeException('Cannot create styles backup directory.');
            }

            foreach (CssDistribution::FILES as $file) {
                $target = $styles . DIRECTORY_SEPARATOR . $file;
                $temporary = $styles . DIRECTORY_SEPARATOR . ".{$file}.{$token}";
                CoreFiles::assertUnlinked($target);
                CoreFiles::assertUnlinked($temporary);

                if (is_file($target)) {
                    $this->writeFile($backup . DIRECTORY_SEPARATOR . $file, CoreFiles::read($target));
                    $backedUp[] = $file;
                }

                $this->writeFile($temporary, $distribution->contents($file));
                $staged[] = $temporary;

                if (! @rename($temporary, $target)) {
                    throw new RuntimeException("Cannot install managed styles: {$file}");
                }

                array_pop($staged);

                if (! chmod($target, 0644)) {
                    throw new RuntimeException("Cannot set managed styles permissions: {$file}");
                }
            }

            foreach (CssDistribution::FILES as $file) {
                if (hash_file('sha256', $styles . DIRECTORY_SEPARATOR . $file) !== $distribution->hashes[$file]) {
                    throw new RuntimeException('Installed styles do not match distribution.');
                }
            }

            return $backup;
        } catch (Throwable $exception) {
            foreach ($backedUp as $file) {
                @rename($backup . DIRECTORY_SEPARATOR . $file, $styles . DIRECTORY_SEPARATOR . $file);
            }

            foreach ($staged as $temporary) {
                if (file_exists($temporary)) {
                    @unlink($temporary);
                }
            }

            @rmdir($backup);

            throw new RuntimeException("Styles update failed; original styles retained/restored: {$exception->getMessage()}", previous: $exception);
        }
    }
}
