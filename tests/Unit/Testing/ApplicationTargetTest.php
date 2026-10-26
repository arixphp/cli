<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use App\Testing\ApplicationTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

class ApplicationTargetTest extends TestCase
{
    private ApplicationTestingFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ApplicationTestingFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_resolves_invocation_relative_and_absolute_paths(): void
    {
        $root = $this->fixture->root;
        $this->assertSame($root, ApplicationTarget::resolve(null, $root));
        $this->assertSame($root, ApplicationTarget::resolve(basename($root), dirname($root)));
        $this->assertSame($root, ApplicationTarget::resolve($root, dirname(__DIR__)));
    }

    #[Test]
    public function it_inspects_metadata_without_executing_application_files_through_the_cli(): void
    {
        $this->fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/","Arix\\\\":"core/"},"files":["trap.php"]}}');
        $this->fixture->write('trap.php', '<?php throw new \\RuntimeException("Metadata inspection executed application PHP");');
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'arix', 'make:controller', 'Inspection', '--path=' . $this->fixture->root, '--no-interaction']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertFileExists($this->fixture->path('app/Controllers/InspectionController.php'));
    }

    public static function requiredFiles(): array
    {

        return array_map(static fn (string $file): array => [$file], ['.arix.json', 'index.php', 'app/bootstrap.php', 'core/autoload.php', 'core/Autoload/AutoloadConfiguration.php', 'core/Autoload/ClassLoader.php', 'tests/autoload.php', 'phpunit.xml']);
    }

    #[Test]
    #[DataProvider('requiredFiles')]
    public function it_rejects_missing_required_files(string $file): void
    {
        unlink($this->fixture->path($file));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains($file);
        ApplicationTarget::resolve($this->fixture->root, dirname(__DIR__));
    }

    #[Test]
    public function it_rejects_a_nonexistent_directory(): void
    {
        $this->expectException(RuntimeException::class);
        ApplicationTarget::resolve($this->fixture->path('missing'), $this->fixture->root);
    }

    #[Test]
    public function it_rejects_linked_files_and_directory_ancestors(): void
    {
        $target = $this->fixture->path('configuration.xml');
        rename($this->fixture->path('phpunit.xml'), $target);
        symlink($target, $this->fixture->path('phpunit.xml'));

        try {
            ApplicationTarget::resolve($this->fixture->root, $this->fixture->root);
            $this->fail('Linked configuration was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('linked', $exception->getMessage());
        }

        unlink($this->fixture->path('phpunit.xml'));
        rename($target, $this->fixture->path('phpunit.xml'));
        symlink($this->fixture->root, $this->fixture->path('alias'));
        $this->expectException(RuntimeException::class);
        ApplicationTarget::resolve($this->fixture->path('alias'), $this->fixture->root);
    }
}
