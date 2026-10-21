<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Tests\Support\ApplicationTestingFixture;

class PestRunnerTest extends TestCase
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
    public function it_runs_application_pest_support_in_a_fresh_process(): void
    {
        $this->fixture->write('tests/ClassTest.php', <<<'PHP'
<?php
declare(strict_types=1);
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
class ClassTest extends TestCase {
    #[Test]
    public function it_runs_application_class(): void { $this->assertTrue(true); }
}
PHP);
        $process = $this->fixture->worker();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('application closure', $process->getOutput());
        $this->assertStringContainsString('it runs application class', $process->getOutput());
        $this->assertStringNotContainsString('PHPUnit 13', $process->getOutput());
    }

    #[Test]
    public function it_never_falls_back_to_cli_classes_or_helpers(): void
    {
        $this->fixture->write('tests/IsolationTest.php', <<<'PHP'
<?php
test('local ownership', function () {
    expect(class_exists(App\Commands\InspireCommand::class))->toBeFalse();
    expect(class_exists(Arix\Application::class))->toBeFalse();
    expect(class_exists(Tests\TestCase::class))->toBeFalse();
    expect(function_exists('something'))->toBeFalse();
    expect((new ReflectionClass(Arix\ApplicationMarker::class))->getFileName())->toBe(realpath(getcwd() . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php'));
    expect(view())->toBe('application-view');
    expect(trans())->toBe('application-trans');
    expect(config())->toBe('application-config');
    expect(env())->toBe('application-env');
});
PHP);
        $process = $this->fixture->worker();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function it_autoloads_application_classes_in_tests_without_a_second_app_loader(): void
    {
        (new Filesystem())->mirror(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core', $this->fixture->path('core'), options: ['override' => true]);
        $this->fixture->write('app/Utils/Formatter.php', '<?php declare(strict_types=1); namespace App\Utils; class Formatter { public static function message(): string { return "application helper"; } }');
        $this->fixture->write('tests/AutoloadTest.php', <<<'PHP'
<?php
declare(strict_types=1);
test('application namespaces use the core autoloader', function (): void {
    expect(\App\Utils\Formatter::message())->toBe('application helper');
    expect(class_exists(\App\Commands\InspireCommand::class))->toBeFalse();
});
PHP);
        $process = $this->fixture->worker();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('application namespaces use the core autoloader', $process->getOutput());
    }

    #[Test]
    public function it_propagates_failed_tests(): void
    {
        $this->fixture->write('tests/ExampleTest.php', '<?php test("deliberate failure", fn () => expect(1)->toBe(2));');
        $process = $this->fixture->worker();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('deliberate failure', $process->getOutput());
    }

    #[Test]
    public function it_rejects_an_empty_suite(): void
    {
        unlink($this->fixture->path('tests/ExampleTest.php'));
        $this->assertNotSame(0, $this->fixture->worker()->getExitCode());
    }

    #[Test]
    public function it_reports_malformed_configuration(): void
    {
        $this->fixture->write('phpunit.xml', '<broken');
        $process = $this->fixture->worker();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Start Tag broken', $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function it_reports_bootstrap_exceptions(): void
    {
        $this->fixture->write('tests/autoload.php', '<?php throw new RuntimeException("application bootstrap failed");');
        $process = $this->fixture->worker();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('application bootstrap failed', $process->getOutput() . $process->getErrorOutput());
    }
}
