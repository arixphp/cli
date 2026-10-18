<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Css\Update\CssDistribution;
use App\Frontend\CssManagedDistribution;
use App\Frontend\FrontendLock;
use App\Frontend\FrontendTransaction;
use App\Frontend\JsManagedDistribution;
use App\Frontend\ManagedFrontendUpdater;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\NewApplicationFixture;

class ManagedFrontendTest extends TestCase
{
    #[Test]
    public function it_does_not_ship_root_level_update_engines(): void
    {
        $this->assertFalse(class_exists('App\\Css\\Update\\CssUpdater'));
        $this->assertFalse(class_exists('App\\Js\\Update\\JsUpdater'));
        $this->assertFalse(class_exists('App\\Css\\Update\\CssTarget'));
        $this->assertFalse(class_exists('App\\Js\\Update\\JsTarget'));
    }

    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
        $this->fixture->write('index.php', '<?php');
        $this->fixture->write('app/bootstrap.php', '<?php');
        $this->fixture->write('styles/app.css', '/* CUSTOM */');
        $this->fixture->write('styles/components.css', '/* CUSTOM COMPONENTS */');
        $this->fixture->write('scripts/app.js', '// CUSTOM');
        $this->fixture->write('fonts/custom.woff2', 'CUSTOM FONT');
        $this->fixture->write('media/custom.webp', 'CUSTOM IMAGE');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_installs_only_managed_subdirectories_and_repeats_without_writes(): void
    {
        $root = dirname(__DIR__, 3);
        $css = new CssManagedDistribution(CssDistribution::bundled($root));
        $js = JsManagedDistribution::bundled($root);
        $updater = new ManagedFrontendUpdater();

        foreach ([$css, $js] as $distribution) {
            (new FrontendTransaction())->install($this->fixture->root, [$distribution], [$distribution->directory() => null]);
            $this->assertSame('up-to-date', $updater->preview($this->fixture->root, $distribution)['action']);
            $this->assertNull($updater->update($this->fixture->root, $distribution, $updater->preview($this->fixture->root, $distribution)));
        }

        $this->assertSame('/* CUSTOM */', file_get_contents($this->fixture->path('styles/app.css')));
        $this->assertSame('/* CUSTOM COMPONENTS */', file_get_contents($this->fixture->path('styles/components.css')));
        $this->assertSame('// CUSTOM', file_get_contents($this->fixture->path('scripts/app.js')));
        $this->assertSame('CUSTOM FONT', file_get_contents($this->fixture->path('fonts/custom.woff2')));
        $this->assertSame('CUSTOM IMAGE', file_get_contents($this->fixture->path('media/custom.webp')));
        $this->assertFileExists($this->fixture->path('scripts/arix/dev-reload.js'));
        $this->assertFileExists($this->fixture->path('scripts/arix/index.js'));
        $this->assertFileExists($this->fixture->path('styles/arix/tokens.css'));
    }

    #[Test]
    public function it_requires_current_skeleton_managed_directories(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('skeleton');
        new ManagedFrontendUpdater()->preview($this->fixture->root, JsManagedDistribution::bundled(dirname(__DIR__, 3)));
    }

    #[Test]
    public function it_rejects_unmanaged_files_even_with_force(): void
    {
        $this->fixture->write('scripts/arix/custom.js', '// KEEP');
        $updater = new ManagedFrontendUpdater();
        $js = JsManagedDistribution::bundled(dirname(__DIR__, 3));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('unmanaged');
        $updater->update($this->fixture->root, $js, [], force: true);
    }

    #[Test]
    public function it_rechecks_the_preview_and_preserves_a_concurrent_edit(): void
    {
        $updater = new ManagedFrontendUpdater();
        $js = JsManagedDistribution::bundled(dirname(__DIR__, 3));
        (new FrontendTransaction())->install($this->fixture->root, [$js], [$js->directory() => null]);
        $preview = $updater->preview($this->fixture->root, $js);
        $this->fixture->write('scripts/arix/index.js', '// CONCURRENT');

        try {
            $updater->update($this->fixture->root, $js, $preview, force: true);
            $this->fail('Concurrent edit overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preview', $exception->getMessage());
            $this->assertSame('// CONCURRENT', file_get_contents($this->fixture->path('scripts/arix/index.js')));
        }
    }

    #[Test]
    public function it_serializes_all_frontend_operations_with_a_stable_lock(): void
    {
        $lock = new FrontendLock();
        $lock->run($this->fixture->root, function () use ($lock): void {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageIsOrContains('lock');
            $lock->run($this->fixture->root, static fn (): bool => true);
        });
    }
}
