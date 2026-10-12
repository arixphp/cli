<?php

declare(strict_types=1);

namespace Tests\Unit\Css\Update;

use App\Css\Update\CssDistribution;
use App\Css\Update\CssUpdater;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CssUpdateFixture;

class CssUpdaterTest extends TestCase
{
    private CssUpdateFixture $fixture;

    private string $application;

    private CssDistribution $distribution;

    /** @var array<string, string> */
    private array $installedBodies;

    /** @var array<string, string> */
    private array $installedHashes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CssUpdateFixture();
        $this->installedBodies = $this->fixture->versionSet('1.0.0');
        $this->installedHashes = $this->fixture->hashes($this->installedBodies);
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->versionSet('2.0.0'), ['1.0.0' => $this->installedHashes]);
        $this->distribution = CssDistribution::bundled($root);
        $this->application = $this->fixture->application();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_previews_an_update_for_a_known_older_release(): void
    {
        $preview = new CssUpdater()->preview($this->application, $this->distribution);

        $this->assertSame('update', $preview['action']);
        $this->assertSame('1.0.0', $preview['installedRelease']);
        $this->assertSame($this->installedHashes, $preview['installedHashes']);
        $this->assertSame('2.0.0', $preview['availableVersion']);
    }

    #[Test]
    public function it_previews_up_to_date_for_identical_files(): void
    {
        foreach ($this->distribution->hashes as $file => $hash) {
            $this->fixture->write("application/styles/{$file}", $this->distribution->contents($file));
        }

        $preview = new CssUpdater()->preview($this->application, $this->distribution);

        $this->assertSame('up-to-date', $preview['action']);
        $this->assertSame('2.0.0', $preview['installedRelease']);
    }

    #[Test]
    public function it_previews_install_when_the_files_are_missing(): void
    {
        $application = $this->fixture->application('fresh', null);
        $preview = new CssUpdater()->preview($application, $this->distribution);

        $this->assertSame('install', $preview['action']);
        $this->assertNull($preview['installedRelease']);
        $this->assertSame(['base.css' => null, 'components.css' => null, 'tokens.css' => null, 'utilities.css' => null], $preview['installedHashes']);
    }

    #[Test]
    public function it_previews_modified_for_a_partial_release_mix(): void
    {
        $this->fixture->write('application/styles/tokens.css', $this->distribution->contents('tokens.css'));
        $preview = new CssUpdater()->preview($this->application, $this->distribution);

        $this->assertSame('modified', $preview['action']);
        $this->assertNull($preview['installedRelease']);
    }

    #[Test]
    public function it_replaces_the_files_and_retains_a_verified_backup(): void
    {
        $backup = new CssUpdater()->update($this->application, $this->distribution, $this->installedHashes);

        $this->assertNotNull($backup);

        foreach (CssDistribution::FILES as $file) {
            $this->assertSame($this->installedBodies[$file], file_get_contents($backup . DIRECTORY_SEPARATOR . $file));
            $this->assertSame($this->distribution->contents($file), file_get_contents($this->fixture->path("application/styles/{$file}")));
            $this->assertSame(0644, fileperms($this->fixture->path("application/styles/{$file}")) & 0777);
        }

        $this->assertSame('/* application entry */', file_get_contents($this->fixture->path('application/styles/app.css')));
        $this->assertFileDoesNotExist($this->fixture->path('application/.css-update.lock'));
    }

    #[Test]
    public function it_returns_null_without_writing_when_already_current(): void
    {
        foreach ($this->distribution->hashes as $file => $hash) {
            $this->fixture->write("application/styles/{$file}", $this->distribution->contents($file));
        }

        $this->assertNull(new CssUpdater()->update($this->application, $this->distribution, $this->distribution->hashes));
        $this->assertSame([], glob($this->fixture->path('application/.styles-backup-*')));
    }

    #[Test]
    public function it_refuses_modified_content_without_force(): void
    {
        $this->fixture->write('application/styles/tokens.css', "/* locally edited */\n");
        $hashes = $this->currentHashes();

        try {
            new CssUpdater()->update($this->application, $this->distribution, $hashes);
            $this->fail('Modified styles were overwritten without force.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('--force', $exception->getMessage());
            $this->assertStringContainsString('locally edited', file_get_contents($this->fixture->path('application/styles/tokens.css')));
        }
    }

    #[Test]
    public function it_overwrites_modified_content_with_force(): void
    {
        $this->fixture->write('application/styles/tokens.css', "/* locally edited */\n");
        $backup = new CssUpdater()->update($this->application, $this->distribution, $this->currentHashes(), true);

        $this->assertNotNull($backup);
        $this->assertSame($this->distribution->contents('tokens.css'), file_get_contents($this->fixture->path('application/styles/tokens.css')));
    }

    #[Test]
    public function it_rejects_a_concurrent_update_without_changing_the_files(): void
    {
        $lock = fopen($this->fixture->path('application/.css-update.lock'), 'c');
        flock($lock, LOCK_EX | LOCK_NB);

        try {
            new CssUpdater()->update($this->application, $this->distribution, $this->installedHashes);
            $this->fail('Concurrent updater acquired the lock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lock', $exception->getMessage());
            $this->assertSame($this->installedBodies['base.css'], file_get_contents($this->fixture->path('application/styles/base.css')));
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
        new CssUpdater()->update($this->application, $this->distribution, ['base.css' => str_repeat('0', 64)]);
    }

    /** @return array<string, string|null> */
    private function currentHashes(): array
    {
        $hashes = [];

        foreach (CssDistribution::FILES as $file) {
            $path = $this->fixture->path("application/styles/{$file}");
            $hashes[$file] = is_file($path) ? hash_file('sha256', $path) : null;
        }

        return $hashes;
    }
}
