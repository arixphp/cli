<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;
use Tests\TestCase;

class TestsCommandTest extends TestCase
{
    private ApplicationTestingFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new ApplicationTestingFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_runs_the_selected_application(): void
    {
        $this->artisan('tests', ['--path' => $this->fixture->root])
            ->expectsOutputToContain('application closure')
            ->assertExitCode(0);
    }

    #[Test]
    public function it_discovers_feature_and_unit_tests_recursively(): void
    {
        $this->fixture->write('tests/Feature/HttpTest.php', '<?php test("feature discovery marker", fn () => expect("feature")->toBe("feature"));');
        $this->fixture->write('tests/Unit/ValueTest.php', '<?php test("unit discovery marker", fn () => expect("unit")->toBe("unit"));');

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'arix', 'tests', '--path=' . $this->fixture->root, '--no-ansi'],
            dirname(__DIR__, 2),
            ['XDEBUG_MODE' => 'off'],
        );
        $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('feature discovery marker', $process->getOutput());
        $this->assertStringContainsString('unit discovery marker', $process->getOutput());
    }

    #[Test]
    public function it_removes_the_stray_phpunit_cache_directory_from_the_application(): void
    {
        $this->fixture->write('--record-test-run-history/test-run-history', 'stale');

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'arix', 'tests', '--path=' . $this->fixture->root, '--no-ansi'],
            dirname(__DIR__, 2),
            ['XDEBUG_MODE' => 'off'],
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->fixture->path('--record-test-run-history'));
    }

    #[Test]
    public function it_rejects_missing_configuration(): void
    {
        unlink($this->fixture->path('phpunit.xml'));
        $this->artisan('tests', ['--path' => $this->fixture->root])
            ->expectsOutputToContain('phpunit.xml')
            ->assertExitCode(2);
    }

    #[Test]
    public function it_runs_from_the_invocation_directory_through_the_real_entry(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'arix', 'tests', '--no-ansi'], $this->fixture->root, ['XDEBUG_MODE' => 'off']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('application closure', $process->getOutput());
    }

    #[Test]
    public function it_propagates_failures_and_preserves_worker_stderr(): void
    {
        $this->fixture->write('tests/ExampleTest.php', '<?php test("public command failure", function () { fwrite(STDERR, "application stderr marker"); expect(1)->toBe(2); });');
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'arix', 'tests', '--path=' . $this->fixture->root, '--no-ansi'], dirname(__DIR__, 2), ['XDEBUG_MODE' => 'off']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('public command failure', $process->getOutput());
        $this->assertStringContainsString('application stderr marker', $process->getErrorOutput());
    }
}
