<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

class StandaloneExecutableTest extends TestCase
{
    private string $binary;

    private ApplicationTestingFixture $fixture;

    protected function setUp(): void
    {
        $binary = getenv('ARIX_TEST_EXECUTABLE');

        if ($binary === false || $binary === '') {
            $this->markTestSkipped('Set ARIX_TEST_EXECUTABLE to a PHPacker build for native integration tests.');
        }

        $this->binary = $binary;
        $this->fixture = new ApplicationTestingFixture();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function runApplication(): Process
    {
        $process = new Process([$this->binary, 'tests', '--no-ansi'], $this->fixture->root, ['PATH' => '']);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    #[Test]
    public function it_runs_application_tests_without_php_in_path(): void
    {
        $this->fixture->write('tests/IsolationTest.php', <<<'PHP'
<?php
test('executable application ownership', function () {
    expect(PHP_SAPI)->toBe('micro');
    expect(class_exists(App\Commands\Tests::class))->toBeFalse();
    expect((new ReflectionClass(Arix\Application::class))->getFileName())->toBe(realpath(getcwd() . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Application.php'));
    expect(class_exists(Tests\TestCase::class))->toBeFalse();
    expect(view())->toBe('application-view');
    expect(config())->toBe('application-config');
});
PHP);
        $process = $this->runApplication();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('executable application ownership', $process->getOutput());
    }

    #[Test]
    public function it_propagates_failed_tests_and_stderr(): void
    {
        $this->fixture->write('tests/ExampleTest.php', '<?php test("executable failure", function () { fwrite(STDERR, "executable stderr"); expect(1)->toBe(2); });');
        $process = $this->runApplication();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('executable stderr', $process->getErrorOutput());
    }

    #[Test]
    public function it_rejects_malformed_xml(): void
    {
        $this->fixture->write('phpunit.xml', '<broken');
        $process = $this->runApplication();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Start Tag broken', $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function it_rejects_empty_suites(): void
    {
        unlink($this->fixture->path('tests/ExampleTest.php'));
        $this->assertNotSame(0, $this->runApplication()->getExitCode());
    }
}
