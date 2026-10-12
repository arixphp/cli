<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Js\Update\JsDistribution;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\JsUpdateFixture;
use Tests\TestCase;

class JsUpdateCommandTest extends TestCase
{
    private JsUpdateFixture $fixture;

    private string $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new JsUpdateFixture();
        $installed = $this->fixture->framework('1.0.0');
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->framework('2.0.0'), ['1.0.0' => hash('sha256', $installed)]);
        $this->application = $this->fixture->application();
        $this->app->instance(JsDistribution::class, JsDistribution::bundled($root));
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_previews_without_writing(): void
    {
        $this->artisan('js:update', ['--path' => $this->application, '--dry-run' => true])
            ->expectsOutputToContain('1.0.0')
            ->expectsOutputToContain('2.0.0')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame($this->fixture->framework('1.0.0'), file_get_contents($this->fixture->path('application/scripts/arix.js')));
        $this->assertFileDoesNotExist($this->fixture->path('application/.js-update.lock'));
        $this->assertSame([], glob($this->fixture->path('application/.scripts-backup-*')));
    }

    #[Test]
    public function it_updates_with_yes_and_reports_the_retained_backup(): void
    {
        $this->artisan('js:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('Backup retained')
            ->assertExitCode(0);

        $this->assertSame($this->fixture->framework('2.0.0'), file_get_contents($this->fixture->path('application/scripts/arix.js')));
    }

    #[Test]
    public function it_reports_up_to_date_without_writing(): void
    {
        $this->fixture->write('application/scripts/arix.js', $this->fixture->framework('2.0.0'));

        $this->artisan('js:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('up to date')
            ->assertExitCode(0);

        $this->assertSame([], glob($this->fixture->path('application/.scripts-backup-*')));
    }

    #[Test]
    public function it_requires_yes_in_noninteractive_mode(): void
    {
        $this->artisan('js:update', ['--path' => $this->application, '--no-interaction' => true])
            ->expectsOutputToContain('--yes')
            ->assertExitCode(2);

        $this->assertSame($this->fixture->framework('1.0.0'), file_get_contents($this->fixture->path('application/scripts/arix.js')));
    }

    #[Test]
    public function it_does_not_write_when_confirmation_is_declined(): void
    {
        $this->artisan('js:update', ['--path' => $this->application])
            ->expectsConfirmation('Replace scripts/arix.js 1.0.0 with 2.0.0?', 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertExitCode(0);

        $this->assertSame($this->fixture->framework('1.0.0'), file_get_contents($this->fixture->path('application/scripts/arix.js')));
    }

    #[Test]
    public function it_requires_force_for_modified_content(): void
    {
        $this->fixture->write('application/scripts/arix.js', "export const version = '9.9.9';\n// locally edited\n");

        $this->artisan('js:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('--force')
            ->assertExitCode(2);

        $this->assertStringContainsString('locally edited', file_get_contents($this->fixture->path('application/scripts/arix.js')));
    }

    #[Test]
    public function it_rejects_an_invalid_application_path(): void
    {
        $this->artisan('js:update', ['--path' => $this->fixture->path('missing'), '--yes' => true])
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->fixture->path('missing/.js-update.lock'));
    }
}
