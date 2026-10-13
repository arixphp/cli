<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\ApplicationInstaller;
use App\Installation\ApplicationPublisher;
use App\Installation\InstallationFiles;
use App\Installation\InstallationTarget;
use App\Installation\RecoveryFailed;
use App\Installation\SafeZipExtractor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FixtureSkeletonSource;
use Tests\Support\NewApplicationFixture;

class ApplicationInstallerTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    private function installer(FixtureSkeletonSource|null $source = null, ApplicationPublisher|null $publisher = null, InstallationFiles|null $files = null): ApplicationInstaller
    {

        return new ApplicationInstaller($source ?? new FixtureSkeletonSource($this->fixture->archive()), new SafeZipExtractor(), $publisher ?? new ApplicationPublisher(), $files ?? new InstallationFiles());
    }

    #[Test]
    public function it_installs_the_skeleton_without_cli_core_vendor_or_vcs(): void
    {
        $target = InstallationTarget::resolve('my-app', '.', $this->fixture->root);
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $this->installer()->install($target));
        $this->assertSame('<?php // downloaded core', file_get_contents($this->fixture->path('my-app/core/autoload.php')));
        $this->assertFileExists($this->fixture->path('my-app/.htaccess'));
        $this->assertDirectoryDoesNotExist($this->fixture->path('my-app/vendor'));
        $this->assertDirectoryDoesNotExist($this->fixture->path('my-app/.git'));
        $this->assertSame([], glob($this->fixture->path('.arix-new-*')));
        $this->assertSame(0755, fileperms($target->path) & 0777);
    }

    #[Test]
    public function it_installs_into_an_existing_empty_directory(): void
    {
        mkdir($this->fixture->path('app'), 0700);
        $this->installer()->install(InstallationTarget::resolve('app', '.', $this->fixture->root));
        $this->assertFileExists($this->fixture->path('app/index.php'));
        $this->assertSame([], glob($this->fixture->path('.arix-new-*')));
    }

    #[Test]
    public function it_cleans_staging_after_a_download_failure(): void
    {
        $source = new FixtureSkeletonSource($this->fixture->archive(), static function (): void {
            throw new RuntimeException('Network failure');
        });

        try {
            $this->installer($source)->install(InstallationTarget::resolve('app', '.', $this->fixture->root));
            $this->fail('Source failure not reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Network failure', $exception->getMessage());
            $this->assertDirectoryDoesNotExist($this->fixture->path('app'));
            $this->assertSame([], glob($this->fixture->path('.arix-new-*')));
        }
    }

    #[Test]
    public function it_preserves_an_empty_destination_on_invalid_archive(): void
    {
        mkdir($this->fixture->path('app'), 0700);
        $target = InstallationTarget::resolve('app', '.', $this->fixture->root);

        try {
            $this->installer(new FixtureSkeletonSource($this->fixture->write('broken.zip', 'broken')))->install($target);
            $this->fail('Invalid archive accepted.');
        } catch (RuntimeException) {
            $this->assertSame(['.', '..'], scandir($target->path));
            $this->assertSame($target->identity['ino'], lstat($target->path)['ino']);
            $this->assertSame([], glob($this->fixture->path('.arix-new-*')));
        }
    }

    #[Test]
    public function it_preserves_content_created_while_downloading(): void
    {
        $source = new FixtureSkeletonSource($this->fixture->archive(), function (): void {
            $this->fixture->write('app/keep', 'third party');
        });

        try {
            $this->installer($source)->install(InstallationTarget::resolve('app', '.', $this->fixture->root));
            $this->fail('Changed destination accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame('third party', file_get_contents($this->fixture->path('app/keep')));
            $this->assertFileDoesNotExist($this->fixture->path('app/index.php'));
            $this->assertSame([], glob($this->fixture->path('.arix-new-*')));
        }
    }

    #[Test]
    public function it_does_not_follow_links_during_cleanup(): void
    {
        $this->fixture->write('outside/keep', 'keep');
        mkdir($this->fixture->path('workspace'));
        symlink($this->fixture->path('outside'), $this->fixture->path('workspace/link'));
        new InstallationFiles()->removeTree($this->fixture->path('workspace'));
        $this->assertSame('keep', file_get_contents($this->fixture->path('outside/keep')));
        $this->assertDirectoryDoesNotExist($this->fixture->path('workspace'));
    }

    #[Test]
    public function it_retains_workspace_on_recovery_failure(): void
    {
        $publisher = new class() extends ApplicationPublisher
        {
            public function publish(InstallationTarget $target, string $workspace): void
            {
                throw new RecoveryFailed("Recovery failed. Inspect retained workspace: {$workspace}");
            }
        };

        try {
            $this->installer(publisher: $publisher)->install(InstallationTarget::resolve('app', '.', $this->fixture->root));
            $this->fail('Recovery failure ignored.');
        } catch (RecoveryFailed) {
            $this->assertCount(1, glob($this->fixture->path('.arix-new-*')));
        }
    }

    #[Test]
    public function it_reports_cleanup_failure_without_hiding_the_original_error(): void
    {
        $source = new FixtureSkeletonSource($this->fixture->archive(), static function (): void {
            throw new RuntimeException('Original network error');
        });
        $files = new class() extends InstallationFiles
        {
            public function removeTree(string $path): void
            {
                throw new RuntimeException('Cleanup error');
            }
        };

        try {
            $this->installer($source, files: $files)->install(InstallationTarget::resolve('app', '.', $this->fixture->root));
            $this->fail('Cleanup failure ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Original network error', $exception->getMessage());
            $this->assertStringContainsString('Cleanup error', $exception->getMessage());
            $this->assertStringContainsString('retained', $exception->getMessage());
        }
    }
}
