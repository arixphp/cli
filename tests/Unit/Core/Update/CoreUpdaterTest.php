<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use App\Core\Update\CoreUpdater;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CoreUpdateFixture;

class CoreUpdaterTest extends TestCase
{
    private CoreUpdateFixture $fixture;

    private string $application;

    private CoreDistribution $distribution;

    private string $previousId;

    protected function setUp(): void
    {
        $this->fixture = new CoreUpdateFixture();
        $this->application = $this->fixture->installed(files: ['Old.php' => '<?php // old'], includeResources: false);
        $source = $this->fixture->core('source', ['New.php' => '<?php // new', 'Http/Kernel.php' => '<?php // updated']);
        CoreDistribution::build($source, $this->fixture->path('core.bundle'));
        $this->distribution = CoreDistribution::load($this->fixture->path('core.bundle'));
        $this->previousId = new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id'];
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_updates_a_previous_core_with_runtime_resources(): void
    {
        $this->fixture->write('application/media/site.webp', 'application image');
        $preview = new CoreInspector()->inspect($this->application, $this->distribution);
        $this->assertSame(['.htaccess', 'Assets/public-policy.json', 'New.php', 'stubs/error.html'], $preview['added']);
        $this->assertFileDoesNotExist($this->fixture->path('application/core/.htaccess'));
        $backup = new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);

        $this->assertNotNull($backup);
        $this->assertSame($this->previousId, new CoreInspector()->installedManifest($backup)['id']);
        $this->assertFileDoesNotExist($backup . DIRECTORY_SEPARATOR . '.htaccess');
        $this->assertSame("Require all denied\n", file_get_contents($this->fixture->path('application/core/.htaccess')));
        $this->assertSame('<html lang="{{locale}}"><title>{{status}} — {{name}}</title><pre>{{message}}</pre></html>', file_get_contents($this->fixture->path('application/core/stubs/error.html')));
        $this->assertSame($this->distribution->manifest['id'], new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
        $this->assertSame('<?php // application entry point', file_get_contents($this->fixture->path('application/index.php')));
        $this->assertSame('<?php // application bootstrap', file_get_contents($this->fixture->path('application/app/bootstrap.php')));
        $this->assertSame('application image', file_get_contents($this->fixture->path('application/media/site.webp')));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0644, fileperms($this->fixture->path('application/core/.htaccess')) & 0777);
            $this->assertSame(0644, fileperms($this->fixture->path('application/core/stubs/error.html')) & 0777);
        }
    }

    #[Test]
    #[DataProvider('resourceChanges')]
    public function it_detects_modified_or_missing_runtime_resources(string $resource, bool $missing): void
    {
        new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);
        $path = $this->fixture->path("application/core/{$resource}");

        if ($missing) {
            unlink($path);
        } else {
            file_put_contents($path, 'local edit');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('local changes');
        new CoreUpdater()->update($this->application, $this->distribution, $this->distribution->manifest['id']);
    }

    public static function resourceChanges(): array
    {

        return [['.htaccess', false], ['.htaccess', true], ['stubs/error.html', false], ['stubs/error.html', true]];
    }

    #[Test]
    public function it_preserves_the_previous_core_when_resource_staging_fails(): void
    {
        $updater = new class() extends CoreUpdater
        {
            protected function writeFile(string $path, string $contents): void
            {
                if (basename($path) === '.htaccess') {
                    throw new RuntimeException('Simulated resource copy failure.');
                }

                parent::writeFile($path, $contents);
            }
        };

        $this->assertRestoredAfterFailure($updater, 'resource copy failure');
        $this->assertFileDoesNotExist($this->fixture->path('application/core/.htaccess'));
    }

    #[Test]
    public function it_restores_the_previous_core_when_installed_resource_verification_fails(): void
    {
        $updater = new class() extends CoreUpdater
        {
            protected function move(string $from, string $to): void
            {
                parent::move($from, $to);

                if (str_contains(basename($from), '.core-stage-') && basename($to) === 'core') {
                    file_put_contents($to . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'error.html', 'corrupted resource');
                }
            }
        };

        $this->assertRestoredAfterFailure($updater, 'local changes');
        $this->assertFileDoesNotExist($this->fixture->path('application/core/stubs/error.html'));
    }

    #[Test]
    public function it_replaces_the_complete_core_and_retains_a_verified_backup(): void
    {
        $this->fixture->write('application/assets/site.css', 'application css');
        $backup = new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);

        $this->assertNotNull($backup);
        $this->assertSame($this->previousId, new CoreInspector()->installedManifest($backup)['id']);
        $this->assertSame($this->distribution->manifest['id'], new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
        $this->assertSame('<?php // new', file_get_contents($this->fixture->path('application/core/New.php')));
        $this->assertFileDoesNotExist($this->fixture->path('application/core/Old.php'));
        $this->assertSame('<?php // application entry point', file_get_contents($this->fixture->path('application/index.php')));
        $this->assertSame('application css', file_get_contents($this->fixture->path('application/assets/site.css')));
        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.json'));
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0644, fileperms($this->fixture->path('application/core/New.php')) & 0777);
        }
    }

    #[Test]
    public function it_does_not_replace_an_identical_distribution(): void
    {
        new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);
        $backups = glob($this->fixture->path('application/.core-backup-*'));
        $result = new CoreUpdater()->update($this->application, $this->distribution, $this->distribution->manifest['id']);

        $this->assertNull($result);
        $this->assertSame($backups, glob($this->fixture->path('application/.core-backup-*')));
    }

    #[Test]
    public function it_rejects_a_concurrent_update_without_changing_the_core(): void
    {
        $lock = fopen($this->fixture->path('application/.core-update.lock'), 'c');
        flock($lock, LOCK_EX | LOCK_NB);

        try {
            new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);
            $this->fail('Concurrent updater acquired the lock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lock', $exception->getMessage());
            $this->assertSame($this->previousId, new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    #[Test]
    public function it_revalidates_the_preview_before_writing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('preview');
        new CoreUpdater()->update($this->application, $this->distribution, str_repeat('0', 64));
    }

    #[Test]
    public function it_preserves_the_original_core_when_staging_fails(): void
    {
        $updater = new class() extends CoreUpdater
        {
            protected function writeFile(string $path, string $contents): void
            {
                if (str_ends_with($path, 'New.php')) {
                    throw new RuntimeException('Simulated copy failure.');
                }

                parent::writeFile($path, $contents);
            }
        };

        $this->assertRestoredAfterFailure($updater, 'copy failure');
    }

    #[Test]
    public function it_restores_the_backup_when_installation_fails(): void
    {
        $updater = new class() extends CoreUpdater
        {
            protected function move(string $from, string $to): void
            {
                if (str_contains(basename($from), '.core-stage-') && basename($to) === 'core') {
                    throw new RuntimeException('Simulated install failure.');
                }

                parent::move($from, $to);
            }
        };

        $this->assertRestoredAfterFailure($updater, 'install failure');
    }

    #[Test]
    public function it_restores_the_backup_when_installed_verification_fails(): void
    {
        $updater = new class() extends CoreUpdater
        {
            protected function move(string $from, string $to): void
            {
                parent::move($from, $to);

                if (str_contains(basename($from), '.core-stage-') && basename($to) === 'core') {
                    file_put_contents($to . DIRECTORY_SEPARATOR . 'New.php', 'corrupted after installation');
                }
            }
        };

        $this->assertRestoredAfterFailure($updater, 'local changes');
    }

    #[Test]
    public function it_retains_recovery_evidence_when_rollback_fails(): void
    {
        $updater = new class() extends CoreUpdater
        {
            protected function move(string $from, string $to): void
            {
                if (basename($to) === 'core') {
                    throw new RuntimeException('Simulated install and rollback failure.');
                }

                parent::move($from, $to);
            }
        };

        try {
            $updater->update($this->application, $this->distribution, $this->previousId);
            $this->fail('Failed rollback reported success.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('recovery', $exception->getMessage());
        }

        $this->assertFileExists($this->fixture->path('application/.core-update.json'));
        $backups = glob($this->fixture->path('application/.core-backup-*'));
        $this->assertCount(1, $backups);
        $this->assertSame($this->previousId, new CoreInspector()->installedManifest($backups[0])['id']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('recovery');
        new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);
    }

    #[Test]
    public function it_retains_a_complete_backup_if_failure_occurs_after_the_backup_move(): void
    {
        $updater = new class() extends CoreUpdater
        {
            protected function writeFile(string $path, string $contents): void
            {
                if (str_contains($path, '.core-update.json.') && str_contains($contents, '"state": "installing"')) {
                    throw new RuntimeException('Simulated journal failure.');
                }

                parent::writeFile($path, $contents);
            }
        };

        $this->assertRestoredAfterFailure($updater, 'journal failure');
    }

    #[Test]
    public function it_rejects_core_changes_made_after_preview(): void
    {
        $this->fixture->write('application/core/NewLocal.php', '<?php // user work');

        try {
            new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);
            $this->fail('Changed core accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('local changes', $exception->getMessage());
        }

        $this->assertSame('<?php // user work', file_get_contents($this->fixture->path('application/core/NewLocal.php')));
        $this->assertSame([], glob($this->fixture->path('application/.core-stage-*')));
    }

    #[Test]
    public function it_keeps_only_the_new_backup_after_consecutive_updates(): void
    {
        $updater = new CoreUpdater();
        $oldBackup = $updater->update($this->application, $this->distribution, $this->previousId);

        foreach (['second', 'third'] as $version) {
            $previousId = new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id'];
            $source = $this->fixture->core('next-source', ['New.php' => "<?php // {$version}"]);
            CoreDistribution::build($source, $this->fixture->path('next.bundle'));
            $distribution = CoreDistribution::load($this->fixture->path('next.bundle'));
            $backup = $updater->update($this->application, $distribution, $previousId);

            $this->assertDirectoryDoesNotExist($oldBackup);
            $this->assertSame([$backup], glob($this->fixture->path('application/.core-backup-*')));
            $this->assertSame($previousId, new CoreInspector()->installedManifest($backup)['id']);
            $this->assertSame($distribution->manifest['id'], new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
            $oldBackup = $backup;
        }
    }

    #[Test]
    #[DataProvider('unsafeBackups')]
    public function it_preserves_unsafe_old_backups_and_reports_a_warning(string $kind): void
    {
        $this->fixture->installed('historical');
        $relative = 'application/.core-backup-' . str_repeat('a', 24);
        $path = $this->fixture->path($relative);
        rename($this->fixture->path('historical/core'), $path);

        if ($kind === 'modified') {
            $this->fixture->write("{$relative}/autoload.php", '<?php // local edit');
        } elseif ($kind === 'extra-file') {
            $this->fixture->write("{$relative}/notes.txt", 'user work');
        } elseif ($kind === 'extra-directory') {
            mkdir($path . DIRECTORY_SEPARATOR . 'notes');
        } elseif ($kind === 'missing-manifest') {
            unlink($path . DIRECTORY_SEPARATOR . 'distribution.json');
        } else {
            $target = $kind === 'root-link' ? $path : $path . DIRECTORY_SEPARATOR . 'linked';

            if ($kind === 'root-link') {
                rename($path, $this->fixture->path('saved-core'));
            }

            if (! @symlink($this->fixture->path('historical'), $target)) {
                $this->markTestSkipped('Symbolic links are unavailable.');
            }
        }

        $updater = new CoreUpdater();
        $backup = $updater->update($this->application, $this->distribution, $this->previousId);

        $this->assertDirectoryExists($path);
        $this->assertFileExists($this->fixture->path('historical/index.php'));
        $this->assertSame($this->previousId, new CoreInspector()->installedManifest($backup)['id']);
        $this->assertCount(1, $updater->cleanupWarnings());
        $this->assertStringContainsString($path, $updater->cleanupWarnings()[0]);
        $this->assertSame($this->distribution->manifest['id'], new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
    }

    public static function unsafeBackups(): array
    {

        return [['modified'], ['extra-file'], ['extra-directory'], ['missing-manifest'], ['root-link'], ['nested-link']];
    }

    #[Test]
    public function it_does_not_prune_backups_when_an_update_requires_recovery(): void
    {
        $this->fixture->installed('historical');
        $path = $this->fixture->path('application/.core-backup-' . str_repeat('b', 24));
        rename($this->fixture->path('historical/core'), $path);
        $this->fixture->write('application/.core-update.json', '{"state":"verifying"}');

        try {
            new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);
            $this->fail('Pending recovery was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('recovery', $exception->getMessage());
        }

        $this->assertDirectoryExists($path);
        $this->assertFileExists($this->fixture->path('application/.core-update.json'));
    }

    #[Test]
    public function it_does_not_delete_directories_with_unrecognized_backup_names(): void
    {
        $this->fixture->installed('historical');
        $path = $this->fixture->path('application/.core-backup-my-files');
        rename($this->fixture->path('historical/core'), $path);
        new CoreUpdater()->update($this->application, $this->distribution, $this->previousId);

        $this->assertDirectoryExists($path);
    }

    private function assertRestoredAfterFailure(CoreUpdater $updater, string $message): void
    {
        $this->fixture->installed('historical');
        $oldBackup = $this->fixture->path('application/.core-backup-' . str_repeat('c', 24));
        rename($this->fixture->path('historical/core'), $oldBackup);
        $oldId = new CoreInspector()->installedManifest($oldBackup)['id'];

        try {
            $updater->update($this->application, $this->distribution, $this->previousId);
            $this->fail('Simulated failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame($this->previousId, new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.json'));
        $this->assertSame([], glob($this->fixture->path('application/.core-stage-*')));
        $this->assertSame($oldId, new CoreInspector()->installedManifest($oldBackup)['id']);
    }
}
