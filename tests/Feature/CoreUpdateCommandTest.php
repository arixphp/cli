<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use PHPUnit\Framework\Attributes\Test;
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
        $this->application = $this->fixture->installed();
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
        $this->artisan('core:update', ['--path' => $this->application, '--dry-run' => true])
            ->expectsOutputToContain('New.php')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($this->fixture->path('application/core/New.php'));
        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.lock'));
        $this->assertSame([], glob($this->fixture->path('application/.core-stage-*')));
    }

    #[Test]
    public function it_updates_with_yes_and_reports_the_retained_backup(): void
    {
        $this->artisan('core:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('Backup retained')
            ->assertExitCode(0);

        $this->assertSame('<?php // new', file_get_contents($this->fixture->path('application/core/New.php')));
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
}
