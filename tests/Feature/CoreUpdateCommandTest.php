<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use App\Core\Update\CoreUpdater;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\CoreUpdateFixture;
use Tests\TestCase;

class CoreUpdateCommandTest extends TestCase
{
    private CoreUpdateFixture $fixture;

    private string $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CoreUpdateFixture();
        $this->application = $this->fixture->installed(includeResources: false);
        CoreDistribution::build($this->fixture->core('source', ['New.php' => '<?php // new']), $this->fixture->path('core.bundle'));
        $this->app->instance(CoreDistribution::class, CoreDistribution::load($this->fixture->path('core.bundle')));
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_previews_without_creating_locks_or_staging(): void
    {
        $this->fixture->installed('historical');
        $backup = $this->fixture->path('application/.core-backup-' . str_repeat('a', 24));
        rename($this->fixture->path('historical/core'), $backup);
        $this->artisan('core:update', ['--path' => $this->application, '--dry-run' => true])
            ->expectsOutputToContain('New.php')
            ->expectsOutputToContain('.htaccess')
            ->expectsOutputToContain('stubs/error.html')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->fixture->path('application/core/New.php'));
        $this->assertFileDoesNotExist($this->fixture->path('application/core/.htaccess'));
        $this->assertFileDoesNotExist($this->fixture->path('application/core/stubs/error.html'));
        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.lock'));
        $this->assertSame([], glob($this->fixture->path('application/.core-stage-*')));
        $this->assertDirectoryExists($backup);
    }

    #[Test]
    public function it_updates_with_yes_and_reports_the_retained_backup(): void
    {
        $this->artisan('core:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('Backup retained')
            ->assertExitCode(0);

        $this->assertSame('<?php // new', file_get_contents($this->fixture->path('application/core/New.php')));
        $this->assertSame("Require all denied\n", file_get_contents($this->fixture->path('application/core/.htaccess')));
        $this->assertFileExists($this->fixture->path('application/core/stubs/error.html'));
        $this->assertSame($this->app->make(CoreDistribution::class)->manifest['id'], new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
    }

    #[Test]
    public function it_uses_the_invocation_directory_when_path_is_omitted(): void
    {
        $cwd = getcwd();
        chdir($this->application);

        try {
            $this->artisan('core:update', ['--dry-run' => true])
                ->expectsOutputToContain($this->application)
                ->assertExitCode(0);
        } finally {
            chdir($cwd);
        }
    }

    #[Test]
    public function it_does_not_write_when_confirmation_is_declined(): void
    {
        $this->artisan('core:update', ['--path' => $this->application])
            ->expectsConfirmation('Traffic is stopped and caches will be refreshed. Update core?', 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.lock'));
        $this->assertFileDoesNotExist($this->fixture->path('application/core/New.php'));
    }

    #[Test]
    public function it_requires_yes_in_noninteractive_mode(): void
    {
        $this->artisan('core:update', ['--path' => $this->application, '--no-interaction' => true])
            ->expectsOutputToContain('--yes')
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.lock'));
    }

    #[Test]
    public function it_rejects_legacy_applications_even_with_yes(): void
    {
        unlink($this->fixture->path('application/.arix.json'));

        $this->artisan('core:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('compatibility')
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.lock'));
    }

    #[Test]
    public function it_warns_without_rolling_back_when_old_backup_deletion_fails(): void
    {
        $this->fixture->installed('historical');
        $path = $this->fixture->path('application/.core-backup-' . str_repeat('a', 24));
        rename($this->fixture->path('historical/core'), $path);
        $this->app->instance(CoreUpdater::class, new class() extends CoreUpdater
        {
            protected function removeTree(string $path): void
            {
                throw new RuntimeException('Simulated backup deletion failure.');
            }
        });

        $this->artisan('core:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('Backup retained')
            ->expectsOutputToContain('Simulated backup deletion failure')
            ->assertExitCode(0);

        $this->assertDirectoryExists($path);
        $this->assertCount(2, glob($this->fixture->path('application/.core-backup-*')));
        $this->assertSame($this->app->make(CoreDistribution::class)->manifest['id'], new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.json'));
    }
}
