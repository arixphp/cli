<?php

declare(strict_types=1);

namespace App\Installation;

use RuntimeException;
use Throwable;

class ApplicationInstaller
{
    public function __construct(
        private readonly SkeletonSource $source,
        private readonly SafeZipExtractor $extractor,
        private readonly ApplicationPublisher $publisher,
        private readonly InstallationFiles $files,
        private readonly ApplicationInstructions $instructions,
    ) {}

    public function install(InstallationTarget $target): string
    {
        $target->assertUnchanged();
        $workspace = $this->files->createWorkspace($target->parent);

        try {
            $commit = $this->source->download($workspace);
            $prepared = $workspace . DIRECTORY_SEPARATOR . 'application';

            if (! @mkdir($prepared, 0700)) {
                throw new RuntimeException('Cannot create prepared application directory.');
            }

            $this->extractor->extract($workspace . DIRECTORY_SEPARATOR . 'skeleton.zip', $prepared);
            $this->instructions->write($prepared);
            $this->publisher->publish($target, $workspace);
        } catch (RecoveryFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            try {
                $this->files->removeTree($workspace);
            } catch (Throwable $cleanup) {
                throw new RuntimeException("{$exception->getMessage()} Cleanup failed: {$cleanup->getMessage()} Inspect retained workspace: {$workspace}", previous: $exception);
            }

            throw $exception;
        }

        try {
            $this->files->removeTree($workspace);
        } catch (Throwable $exception) {
            throw new RuntimeException("Application installed at {$target->path}, but cleanup failed. Inspect retained workspace: {$workspace}. {$exception->getMessage()}", previous: $exception);
        }

        return $commit;
    }
}
