<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use App\Core\Update\GitHubCoreDistribution;
use App\Installation\HttpsDownloader;
use App\Installation\HttpTransport;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CoreUpdateFixture;
use Tests\Support\InstallationTransport;
use Tests\TestCase;
use ZipArchive;

class CoreOnlineUpdateCommandTest extends TestCase
{
    private CoreUpdateFixture $fixture;

    private string $application;

    private CoreDistribution $candidate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CoreUpdateFixture();
        $this->application = $this->fixture->installed();
        CoreDistribution::build($this->fixture->core('source', ['Online.php' => '<?php // online']), $this->fixture->path('online.bundle'));
        $this->candidate = CoreDistribution::load($this->fixture->path('online.bundle'));
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_keeps_the_default_update_offline_even_when_github_is_unavailable(): void
    {
        $this->app->instance(HttpTransport::class, new InstallationTransport([]));
        $this->app->instance(CoreDistribution::class, $this->candidate);

        $this->artisan('core:update', ['--path' => $this->application, '--yes' => true])
            ->expectsOutputToContain('Source: embedded')
            ->assertExitCode(0);

        $this->assertFileExists($this->fixture->path('application/core/Online.php'));
    }

    #[Test]
    public function it_previews_a_remote_bundle_without_touching_the_application(): void
    {
        $this->remote();
        $before = new CoreInspector()->installedManifest($this->fixture->path('application/core'));

        $this->artisan('core:update', ['--path' => $this->application, '--online' => true, '--dry-run' => true])
            ->expectsOutputToContain('Source: GitHub arixphp/cli release 1.1.0')
            ->expectsOutputToContain('Online.php')
            ->assertExitCode(0);

        $this->assertSame($before, new CoreInspector()->installedManifest($this->fixture->path('application/core')));
        $this->assertSame([], glob($this->fixture->path('application/.core-*')));
        $this->assertSame([], glob($this->fixture->path('arix-core-download-*')));
    }

    #[Test]
    public function it_installs_a_remote_bundle_using_the_existing_backup_transaction(): void
    {
        $this->remote();
        $this->app->bind(CoreDistribution::class, static function (): never {
            throw new \RuntimeException('Online mode must not load the embedded bundle.');
        });

        $this->artisan('core:update', ['--path' => $this->application, '--online' => true, '--yes' => true])
            ->expectsOutputToContain('Backup retained')
            ->assertExitCode(0);

        $this->assertSame($this->candidate->manifest['id'], new CoreInspector()->installedManifest($this->fixture->path('application/core'))['id']);
        $this->assertCount(1, glob($this->fixture->path('application/.core-backup-*')));
        $this->assertSame([], glob($this->fixture->path('arix-core-download-*')));
    }

    #[Test]
    public function it_does_not_silently_fall_back_to_an_embedded_bundle_on_network_failure(): void
    {
        $this->app->instance(HttpTransport::class, new InstallationTransport([]));
        $this->app->instance(CoreDistribution::class, $this->candidate);

        $this->artisan('core:update', ['--path' => $this->application, '--online' => true, '--yes' => true])
            ->expectsOutputToContain('without --online')
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->fixture->path('application/core/Online.php'));
        $this->assertSame([], glob($this->fixture->path('application/.core-*')));
    }

    #[Test]
    public function it_still_requires_confirmation_for_an_online_noninteractive_update(): void
    {
        $this->remote();

        $this->artisan('core:update', ['--path' => $this->application, '--online' => true, '--no-interaction' => true])
            ->expectsOutputToContain('--yes')
            ->assertExitCode(2);

        $this->assertSame([], glob($this->fixture->path('application/.core-*')));
    }

    private function remote(): void
    {
        $sha = str_repeat('a', 40);
        $zip = new ZipArchive();
        $zip->open($this->fixture->path('release.zip'), ZipArchive::CREATE);
        $zip->addFromString("cli-{$sha}/resources/core.bundle", file_get_contents($this->fixture->path('online.bundle')));
        $zip->close();
        $transport = new InstallationTransport([
            ['status' => 200, 'body' => '{"draft":false,"prerelease":false,"tag_name":"1.1.0"}'],
            ['status' => 200, 'body' => json_encode(['sha' => $sha], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => file_get_contents($this->fixture->path('release.zip'))],
        ]);
        $this->app->instance(GitHubCoreDistribution::class, new GitHubCoreDistribution(new HttpsDownloader($transport), $this->fixture->root));
    }
}
