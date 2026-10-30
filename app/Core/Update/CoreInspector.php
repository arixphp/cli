<?php

declare(strict_types=1);

namespace App\Core\Update;

use RuntimeException;

class CoreInspector
{
    /** @return array{installed: array, added: list<string>, modified: list<string>, removed: list<string>} */
    public function inspect(string $application, CoreDistribution $distribution): array
    {
        $application = ApplicationTarget::resolve($application, $application);
        $journal = $application . DIRECTORY_SEPARATOR . '.core-update.json';
        CoreFiles::assertUnlinked($journal);

        if (file_exists($journal)) {
            throw new RuntimeException("Pending update requires manual recovery: {$journal}");
        }

        foreach (scandir($application) ?: [] as $entry) {
            if (str_starts_with($entry, '.core-stage-') || str_starts_with($entry, '.core-update.json.')) {
                throw new RuntimeException("Pending update requires manual recovery: {$entry}");
            }
        }

        $installed = $this->installedManifest($application . DIRECTORY_SEPARATOR . 'core');
        $added = [];
        $modified = [];
        $removed = [];

        foreach ($distribution->manifest['files'] as $path => $hash) {
            if (! isset($installed['files'][$path])) {
                $added[] = $path;
            } elseif ($installed['files'][$path] !== $hash) {
                $modified[] = $path;
            }
        }

        foreach ($installed['files'] as $path => $hash) {
            if (! isset($distribution->manifest['files'][$path])) {
                $removed[] = $path;
            }
        }

        return ['installed' => $installed, 'added' => $added, 'modified' => $modified, 'removed' => $removed];
    }

    /** @return array<string, mixed> */
    public function installedManifest(string $core): array
    {
        CoreFiles::assertUnlinked($core);
        $path = $core . DIRECTORY_SEPARATOR . 'distribution.json';
        CoreFiles::assertUnlinked($path);

        if (! is_file($path)) {
            throw new RuntimeException('Installed core manifest is missing; refusing unmanaged core.');
        }

        $manifest = CoreDistribution::validateInstalledManifest(CoreDistribution::decode(CoreFiles::read($path)));

        if (CoreFiles::inventory($core, strictDirectories: true) !== $manifest['files']) {
            throw new RuntimeException('Installed core has local changes; refusing to overwrite.');
        }

        return $manifest;
    }
}
