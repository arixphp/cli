<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Css\Update\CssDistribution;
use App\Frontend\CssManagedDistribution;
use App\Frontend\FrontendFiles;
use App\Frontend\FrontendTransaction;
use App\Frontend\JsManagedDistribution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\NewApplicationFixture;

class FrontendTransactionTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
        $this->fixture->write('index.php', '<?php');
        $this->fixture->write('app/bootstrap.php', '<?php');
        $this->fixture->write('scripts/app.js', '// CUSTOM ENTRY');
        $this->fixture->write('scripts/arix.js', '// CUSTOM LEGACY');
        $this->fixture->write('styles/app.css', '/* CUSTOM ENTRY */');
        $this->fixture->write('styles/components.css', '/* CUSTOM LEGACY */');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    private function distributions(): array
    {
        $root = dirname(__DIR__, 3);

        return [new CssManagedDistribution(CssDistribution::bundled($root)), JsManagedDistribution::bundled($root)];
    }

    #[Test]
    public function it_installs_both_managed_targets_without_touching_application_files(): void
    {
        $this->assertDirectoryDoesNotExist($this->fixture->path('scripts/arix'));
        $this->assertFileDoesNotExist($this->fixture->path('.frontend.lock'));

        (new FrontendTransaction())->install($this->fixture->root, $this->distributions(), ['styles/arix' => null, 'scripts/arix' => null]);

        $this->assertSame('// CUSTOM ENTRY', file_get_contents($this->fixture->path('scripts/app.js')));
        $this->assertSame('// CUSTOM LEGACY', file_get_contents($this->fixture->path('scripts/arix.js')));
        $this->assertSame('/* CUSTOM ENTRY */', file_get_contents($this->fixture->path('styles/app.css')));
        $this->assertSame('/* CUSTOM LEGACY */', file_get_contents($this->fixture->path('styles/components.css')));
        $this->assertFileExists($this->fixture->path('scripts/arix/dev-reload.js'));
        $this->assertFileExists($this->fixture->path('styles/arix/base.css'));
        $this->assertFileDoesNotExist($this->fixture->path('.frontend-update.json'));
    }

    #[Test]
    public function it_rolls_back_both_directories_if_the_second_install_fails(): void
    {
        $transaction = new class() extends FrontendTransaction {
            private bool $failed = false;

            protected function move(string $from, string $to): void
            {
                if (! $this->failed && str_ends_with($to, DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'arix')) {
                    $this->failed = true;
                    throw new RuntimeException('Injected second installation failure.');
                }

                parent::move($from, $to);
            }
        };

        try {
            $transaction->install($this->fixture->root, $this->distributions(), ['styles/arix' => null, 'scripts/arix' => null]);

            $this->fail('Injected failure ignored.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('restored', $exception->getMessage());
            $this->assertDirectoryDoesNotExist($this->fixture->path('styles/arix'));
            $this->assertDirectoryDoesNotExist($this->fixture->path('scripts/arix'));
            $this->assertFileDoesNotExist($this->fixture->path('.frontend-update.json'));
            $this->assertSame('// CUSTOM LEGACY', file_get_contents($this->fixture->path('scripts/arix.js')));
        }
    }

    #[Test]
    public function it_refuses_untrusted_recovery_instructions_without_touching_their_targets(): void
    {
        $outside = $this->fixture->write('sibling/keep.txt', 'KEEP');

        $this->fixture->write('.frontend-update.json', json_encode(['stage' => $outside, 'backup' => '../sibling'], JSON_THROW_ON_ERROR));

        try {
            FrontendFiles::assertNoPendingOperation($this->fixture->root);

            $this->fail('Untrusted pending journal accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('recovery', $exception->getMessage());
            $this->assertSame('KEEP', file_get_contents($outside));
            $this->assertFileExists($this->fixture->path('.frontend-update.json'));
        }
    }

    #[Test]
    public function it_refuses_conflicting_managed_destinations(): void
    {
        $this->fixture->write('scripts/arix/index.js', '// UNKNOWN CUSTOM');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('preview');

        (new FrontendTransaction())->install($this->fixture->root, $this->distributions(), ['styles/arix' => null, 'scripts/arix' => null]);
    }
}
