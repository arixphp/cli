<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use App\Core\Update\CoreUpdater;
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
        $this->application = $this->fixture->installed(files: ['Old.php' => '<?php // old']);
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
        $this->assertSame(0644, fileperms($this->fixture->path('application/core/New.php')) & 0777);
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

    private function assertRestoredAfterFailure(CoreUpdater $updater, string $message): void
    {
        try {
            $updater->update($this->application, $this->distribution, $this->previousId);
            $this->fail('Simulated failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame($this->previousId, new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.json'));
        $this->assertSame([], glob($this->fixture->path('application/.core-stage-*')));
    }
}
