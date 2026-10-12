<?php

declare(strict_types=1);

namespace App\Js\Update;

use App\Core\Update\CoreFiles;
use RuntimeException;
use Throwable;

use function is_resource;
use function is_string;

class JsUpdater
{
    /** @return array{installedVersion: string|null, installedHash: string|null, availableVersion: string, action: string} */
    public function preview(string $application, JsDistribution $distribution): array
    {
        $installed = $this->installed($application);

        if ($installed === null) {

            return ['installedVersion' => null, 'installedHash' => null, 'availableVersion' => $distribution->version, 'action' => 'install'];
        }

        if ($installed['hash'] === $distribution->hash) {

            return ['installedVersion' => $installed['version'], 'installedHash' => $installed['hash'], 'availableVersion' => $distribution->version, 'action' => 'up-to-date'];
        }

        if ($distribution->releaseOf($installed['hash']) !== null) {

            return ['installedVersion' => $installed['version'], 'installedHash' => $installed['hash'], 'availableVersion' => $distribution->version, 'action' => 'update'];
        }

        return ['installedVersion' => $installed['version'], 'installedHash' => $installed['hash'], 'availableVersion' => $distribution->version, 'action' => 'modified'];
    }

    public function update(string $application, JsDistribution $distribution, string|null $expectedHash, bool $force = false): string|null
    {
        $application = JsTarget::resolve($application, $application);
        $lockPath = $application . DIRECTORY_SEPARATOR . '.js-update.lock';

        CoreFiles::assertUnlinked($lockPath);

        if (file_exists($lockPath) && ! is_file($lockPath)) {
            throw new RuntimeException('Invalid update lock file.');
        }

        $lock = @fopen($lockPath, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('Cannot acquire JavaScript update lock.');
        }

        $completed = false;

        try {
            if (! chmod($lockPath, 0600)) {
                throw new RuntimeException('Cannot secure JavaScript update lock.');
            }

            $preview = $this->preview($application, $distribution);

            if ($expectedHash !== $preview['installedHash']) {
                throw new RuntimeException('Installed framework changed since preview; run the command again.');
            }

            if ($preview['action'] === 'up-to-date') {
                $completed = true;

                return null;
            }

            if ($preview['action'] === 'modified' && ! $force) {
                throw new RuntimeException('Installed framework was modified locally; re-run with --force to overwrite it.');
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

    /** @return array{version: string|null, hash: string}|null */
    private function installed(string $application): array|null
    {
        $path = $application . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'arix.js';
        CoreFiles::assertUnlinked($path);

        if (! is_file($path)) {

            return null;
        }

        $contents = CoreFiles::read($path);

        try {
            $version = JsDistribution::parseVersion($contents);
        } catch (RuntimeException) {
            $version = null;
        }

        $hash = hash_file('sha256', $path);

        if (! is_string($hash)) {
            throw new RuntimeException('Cannot hash the installed framework.');
        }

        return ['version' => $version, 'hash' => $hash];
    }

    private function install(string $application, JsDistribution $distribution): string
    {
        $scripts = $application . DIRECTORY_SEPARATOR . 'scripts';
        $token = bin2hex(random_bytes(12));
        $backup = $application . DIRECTORY_SEPARATOR . ".scripts-backup-{$token}";
        $temporary = $scripts . DIRECTORY_SEPARATOR . ".arix-{$token}.js";
        $target = $scripts . DIRECTORY_SEPARATOR . 'arix.js';
        $backedUp = false;

        try {
            foreach ([$backup, $temporary, $target] as $path) {
                CoreFiles::assertUnlinked($path);
            }

            if (! mkdir($backup, 0755, true) || ! chmod($backup, 0755)) {
                throw new RuntimeException('Cannot create JavaScript backup directory.');
            }

            if (is_file($target)) {
                $this->writeFile($backup . DIRECTORY_SEPARATOR . 'arix.js', CoreFiles::read($target));
                $backedUp = true;
            }

            $this->writeFile($temporary, $distribution->contents());

            if (! @rename($temporary, $target)) {
                throw new RuntimeException('Cannot install the JavaScript framework.');
            }

            if (! chmod($target, 0644)) {
                throw new RuntimeException('Cannot set framework file permissions.');
            }

            if (hash_file('sha256', $target) !== $distribution->hash) {
                throw new RuntimeException('Installed framework does not match distribution.');
            }

            return $backup;
        } catch (Throwable $exception) {
            if ($backedUp) {
                @rename($backup . DIRECTORY_SEPARATOR . 'arix.js', $target);
                @rmdir($backup);
            }

            if (file_exists($temporary)) {
                @unlink($temporary);
            }

            throw new RuntimeException("JavaScript update failed; original framework retained/restored: {$exception->getMessage()}", previous: $exception);
        }
    }
}
