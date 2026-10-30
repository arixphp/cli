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
    expect((new ReflectionClass(Arix\Application::class))->getFileName())->toBe(realpath(getcwd() . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Application.php'));
    expect(class_exists(Tests\TestCase::class))->toBeFalse();
    expect(function_exists('something'))->toBeFalse();
    expect((new ReflectionClass(Arix\ApplicationMarker::class))->getFileName())->toBe(realpath(getcwd() . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'ApplicationMarker.php'));
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
    public function it_loads_custom_production_files_and_explicit_development_namespaces(): void
    {
        $this->fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"Domain\\\\":"domain/","Arix\\\\":"core/"},"files":["core/functions.php","checks/helpers.php"]},"autoload-dev":{"psr-4":{"Checks\\\\":"checks/"}}}');
        $this->fixture->write('domain/Value.php', '<?php namespace Domain; class Value {}');
        $this->fixture->write('checks/Probe.php', '<?php namespace Checks; class Probe {}');
        $this->fixture->write('checks/helpers.php', '<?php $GLOBALS["eager_probe"] = new \\Checks\\Probe(); function arix_probe(): string { return "development"; }');
        $this->fixture->write('tests/AutoloadTest.php', <<<'PHP'
<?php
test('declarative namespaces', function () {
    expect(new Domain\Value())->toBeInstanceOf(Domain\Value::class);
    expect(new Checks\Probe())->toBeInstanceOf(Checks\Probe::class);
    expect($GLOBALS['eager_probe'])->toBeInstanceOf(Checks\Probe::class);
    expect(arix_probe())->toBe('development');
});
PHP);
        $process = $this->fixture->worker();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function it_loads_application_helpers_before_competing_composer_helpers(): void
    {
        $this->fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"Arix\\\\":"core/"},"files":["helpers.php"]}}');
        $this->fixture->write('helpers.php', '<?php function collect(): string { return "application collection"; }');
        $this->fixture->write('tests/HelpersTest.php', '<?php test("application helpers win", fn () => expect(collect())->toBe("application collection"));');
        $process = $this->fixture->worker();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    #[Test]
    public function it_uses_local_native_declarations_and_one_development_loader(): void
    {
        $this->fixture->write('tests/NativeTest.php', <<<'PHP'
<?php
test('native ownership', function () {
    $root = getcwd() . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Autoload' . DIRECTORY_SEPARATOR;
    expect((new ReflectionClass(\Arix\Autoload\ClassLoader::class))->getFileName())->toBe(realpath($root . 'ClassLoader.php'));
    expect((new ReflectionClass(\Arix\Autoload\AutoloadConfiguration::class))->getFileName())->toBe(realpath($root . 'AutoloadConfiguration.php'));
    $native = array_filter(spl_autoload_functions(), static fn ($callback) => $callback instanceof Closure && (new ReflectionFunction($callback))->getClosureThis() instanceof \Arix\Autoload\ClassLoader);
    expect(count($native))->toBe(1);
    expect((new ReflectionFunction(reset($native)))->getClosureThis()->development)->toBeTrue();
});
PHP);
        $process = $this->fixture->worker();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
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
