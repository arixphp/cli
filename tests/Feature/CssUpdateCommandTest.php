<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Css\Update\CssDistribution;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CssUpdateFixture;
use Tests\TestCase;

class CssUpdateCommandTest extends TestCase
{
    private CssUpdateFixture $fixture;

    private string $application;

    /** @var array<string, string> */
    private array $installedBodies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CssUpdateFixture();
        $this->installedBodies = $this->fixture->versionSet('1.0.0');
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->versionSet('2.0.0'), ['1.0.0' => $this->fixture->hashes($this->installedBodies)]);
        $this->application = $this->fixture->application();
        foreach ($this->installedBodies as $file => $body) {
            $this->fixture->write("application/styles/arix/{$file}", $body);
        }
        $this->app->instance(CssDistribution::class, CssDistribution::bundled($root));
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_previews_without_writing(): void
    {
        $this->artisan('css:update', ['--path' => $this->application, '--dry-run' => true])
            ->expectsOutputToContain('1.0.0')
            ->expectsOutputToContain('2.0.0')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertSame($this->installedBodies['base.css'], file_get_contents($this->fixture->path('application/styles/arix/base.css')));
        $this->assertFileDoesNotExist($this->fixture->path('application/.css-update.lock'));
        $this->assertSame([], glob($this->fixture->path('application/.styles-backup-*')));
    }

    #[Test]
    public function it_updates_with_yes_and_reports_the_retained_backup(): void
    {
        $this->artisan('css:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('Backup retained')
            ->assertExitCode(0);

        $this->assertSame($this->fixture->stylesheet('base.css', '2.0.0'), file_get_contents($this->fixture->path('application/styles/arix/base.css')));
        $this->assertSame($this->installedBodies['base.css'], file_get_contents($this->fixture->path('application/styles/base.css')));
    }

    #[Test]
    public function it_reports_up_to_date_without_writing(): void
    {
        foreach ($this->installedBodies as $file => $body) {
            $this->fixture->write("application/styles/arix/{$file}", $this->fixture->stylesheet($file, '2.0.0'));
        }

        $this->artisan('css:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('up to date')
            ->assertExitCode(0);

        $this->assertSame([], glob($this->fixture->path('application/.styles-backup-*')));
    }

    #[Test]
    public function it_requires_yes_in_noninteractive_mode(): void
    {
        $this->artisan('css:update', ['--path' => $this->application, '--no-interaction' => true])
            ->expectsOutputToContain('--yes')
            ->assertExitCode(2);

        $this->assertSame($this->installedBodies['base.css'], file_get_contents($this->fixture->path('application/styles/base.css')));
    }

    #[Test]
    public function it_does_not_write_when_confirmation_is_declined(): void
    {
        $this->artisan('css:update', ['--path' => $this->application])
            ->expectsConfirmation('Replace managed styles 1.0.0 with 2.0.0?', 'no')
            ->expectsOutputToContain('Cancelled')
            ->assertExitCode(0);

        $this->assertSame($this->installedBodies['base.css'], file_get_contents($this->fixture->path('application/styles/base.css')));
    }

    #[Test]
    public function it_requires_force_for_modified_content(): void
    {
        $this->fixture->write('application/styles/arix/tokens.css', "/* locally edited */\n");

        $this->artisan('css:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('--force')
            ->assertExitCode(2);

        $this->assertStringContainsString('locally edited', file_get_contents($this->fixture->path('application/styles/arix/tokens.css')));
    }

    #[Test]
    public function it_rejects_an_invalid_application_path(): void
    {
        $this->artisan('css:update', ['--path' => $this->fixture->path('missing'), '--yes' => true])
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->fixture->path('missing/.css-update.lock'));
    }
}
