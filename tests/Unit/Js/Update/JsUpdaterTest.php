<?php

declare(strict_types=1);

namespace Tests\Unit\Js\Update;

use App\Js\Update\JsDistribution;
use App\Js\Update\JsUpdater;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\JsUpdateFixture;

class JsUpdaterTest extends TestCase
{
    private JsUpdateFixture $fixture;

    private string $application;

    private JsDistribution $distribution;

    private string $installedHash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new JsUpdateFixture();
        $installed = $this->fixture->framework('1.0.0');
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->framework('2.0.0'), ['1.0.0' => hash('sha256', $installed)]);
        $this->distribution = JsDistribution::bundled($root);
        $this->application = $this->fixture->application();
        $this->installedHash = hash_file('sha256', $this->fixture->path('application/scripts/arix.js'));
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_previews_an_update_for_a_known_older_release(): void
    {
        $preview = new JsUpdater()->preview($this->application, $this->distribution);

        $this->assertSame('update', $preview['action']);
        $this->assertSame('1.0.0', $preview['installedVersion']);
        $this->assertSame($this->installedHash, $preview['installedHash']);
        $this->assertSame('2.0.0', $preview['availableVersion']);
    }

    #[Test]
    public function it_previews_up_to_date_for_an_identical_file(): void
    {
        $this->fixture->write('application/scripts/arix.js', $this->distribution->contents());
        $preview = new JsUpdater()->preview($this->application, $this->distribution);

        $this->assertSame('up-to-date', $preview['action']);
        $this->assertSame('2.0.0', $preview['installedVersion']);
    }

    #[Test]
    public function it_previews_install_when_the_file_is_missing(): void
    {
        $application = $this->fixture->application('fresh', null);
        $preview = new JsUpdater()->preview($application, $this->distribution);

        $this->assertSame('install', $preview['action']);
        $this->assertNull($preview['installedVersion']);
        $this->assertNull($preview['installedHash']);
    }

    #[Test]
    public function it_previews_modified_for_unknown_content(): void
    {
        $this->fixture->write('application/scripts/arix.js', "export const version = '9.9.9';\n// locally edited\n");
        $preview = new JsUpdater()->preview($this->application, $this->distribution);

        $this->assertSame('modified', $preview['action']);
        $this->assertSame('9.9.9', $preview['installedVersion']);
    }

    #[Test]
    public function it_replaces_the_file_and_retains_a_verified_backup(): void
    {
        $backup = new JsUpdater()->update($this->application, $this->distribution, $this->installedHash);

        $this->assertNotNull($backup);
        $this->assertSame($this->fixture->framework('1.0.0'), file_get_contents($backup . DIRECTORY_SEPARATOR . 'arix.js'));
        $this->assertSame($this->distribution->contents(), file_get_contents($this->fixture->path('application/scripts/arix.js')));
        $this->assertSame('// application entry', file_get_contents($this->fixture->path('application/scripts/app.js')));
        $this->assertSame(0644, fileperms($this->fixture->path('application/scripts/arix.js')) & 0777);
        $this->assertFileDoesNotExist($this->fixture->path('application/.js-update.lock'));
    }

    #[Test]
    public function it_returns_null_without_writing_when_already_current(): void
    {
        $this->fixture->write('application/scripts/arix.js', $this->distribution->contents());

        $this->assertNull(new JsUpdater()->update($this->application, $this->distribution, hash('sha256', $this->distribution->contents())));
        $this->assertSame([], glob($this->fixture->path('application/.scripts-backup-*')));
    }

    #[Test]
    public function it_refuses_modified_content_without_force(): void
    {
        $this->fixture->write('application/scripts/arix.js', "export const version = '9.9.9';\n// locally edited\n");
        $hash = hash_file('sha256', $this->fixture->path('application/scripts/arix.js'));

        try {
            new JsUpdater()->update($this->application, $this->distribution, $hash);
            $this->fail('Modified framework was overwritten without force.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('--force', $exception->getMessage());
            $this->assertStringContainsString('locally edited', file_get_contents($this->fixture->path('application/scripts/arix.js')));
        }
    }

    #[Test]
    public function it_overwrites_modified_content_with_force(): void
    {
        $this->fixture->write('application/scripts/arix.js', "export const version = '9.9.9';\n// locally edited\n");
        $hash = hash_file('sha256', $this->fixture->path('application/scripts/arix.js'));
        $backup = new JsUpdater()->update($this->application, $this->distribution, $hash, true);

        $this->assertNotNull($backup);
        $this->assertSame($this->distribution->contents(), file_get_contents($this->fixture->path('application/scripts/arix.js')));
    }

    #[Test]
    public function it_rejects_a_concurrent_update_without_changing_the_file(): void
    {
        $lock = fopen($this->fixture->path('application/.js-update.lock'), 'c');
        flock($lock, LOCK_EX | LOCK_NB);

        try {
            new JsUpdater()->update($this->application, $this->distribution, $this->installedHash);
            $this->fail('Concurrent updater acquired the lock.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('lock', $exception->getMessage());
            $this->assertSame($this->fixture->framework('1.0.0'), file_get_contents($this->fixture->path('application/scripts/arix.js')));
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
        new JsUpdater()->update($this->application, $this->distribution, str_repeat('0', 64));
    }
}
