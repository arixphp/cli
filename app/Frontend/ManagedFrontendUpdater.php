<?php

declare(strict_types=1);

namespace App\Frontend;

use RuntimeException;

class ManagedFrontendUpdater
{
    /** @return array{installed: array<string, string>|null, installedVersion: string|null, availableVersion: string, action: string} */
    public function preview(string $application, ManagedDistribution $distribution): array
    {
        $application = FrontendFiles::application($application);

        FrontendFiles::assertNoPendingOperation($application);

        $installed = FrontendFiles::inventory($application, $distribution->directory(), array_keys($distribution->inventory()));

        if ($installed === null) {
            throw new RuntimeException('Invalid current skeleton: managed frontend directory is missing.');
        }

        $recorded = $distribution->inventory();

        ksort($recorded);

        $release = $installed === null ? null : $distribution->releaseOf($installed);
        $action = $this->resolveAction($recorded, $installed, $release);

        return ['installed' => $installed, 'installedVersion' => $release, 'availableVersion' => $distribution->version(), 'action' => $action];
    }

    public function update(string $application, ManagedDistribution $distribution, array $expected, bool $force = false): string|null
    {
        $application = FrontendFiles::application($application);

        return (new FrontendLock())->run($application, function () use ($application, $distribution, $expected, $force): string|null {
            $preview = $this->preview($application, $distribution);

            if ($preview !== $expected) {
                throw new RuntimeException('Managed frontend changed since preview; retry the operation.');
            }

            if ($preview['action'] === 'up-to-date') {

                return null;
            }

            if ($preview['action'] === 'modified' && ! $force) {
                throw new RuntimeException('Managed frontend was modified locally; use --force only to replace managed files.');
            }

            return (new FrontendTransaction())->install($application, [$distribution], [$distribution->directory() => $preview['installed']]);
        });
    }

    protected function resolveAction(array $recorded, array|null $installed, string|null $release): string
    {
        if ($installed === null) {
            return 'install';
        }

        if ($installed === $recorded) {
            return 'up-to-date';
        }

        if ($release === null) {
            return 'modified';
        }

        return 'update';
    }
}
