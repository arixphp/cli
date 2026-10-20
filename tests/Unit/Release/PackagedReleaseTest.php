<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use Arix\Release\Release;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

class PackagedReleaseTest extends TestCase
{
    private string $binary;

    private bool $native;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'Release.php';
        $executable = getenv('ARIX_TEST_EXECUTABLE');
        $phar = getenv('ARIX_TEST_PHAR');
        $this->native = $executable !== false && $executable !== '';
        $this->binary = $this->native ? $executable : ($phar ?: '');

        if ($this->binary === '') {
            $this->markTestSkipped('Set ARIX_TEST_EXECUTABLE or ARIX_TEST_PHAR for release integration.');
        }

        $this->assertFileExists($this->binary);
    }

    #[Test]
    public function it_runs_packaged_tests_with_the_required_embedded_runtime(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $lock = json_decode(file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'composer.lock'), true, flags: JSON_THROW_ON_ERROR);
            $extensions = ['mbstring', 'curl', 'zip'];

            foreach ($lock['packages'] as $package) {
                foreach ($package['require'] ?? [] as $requirement => $constraint) {
                    if (str_starts_with($requirement, 'ext-')) {
                        $extensions[] = substr($requirement, 4);
                    }
                }
            }

            $extensions = var_export(array_values(array_unique($extensions)), true);
            $sapi = $this->native ? 'micro' : 'cli';
            $fixture->write('tests/RuntimeTest.php', <<<PHP
<?php
declare(strict_types=1);
test('release runtime requirements', function () {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80401);
    expect(PHP_SAPI)->toBe('{$sapi}');
    foreach ({$extensions} as \$extension) {
        expect(extension_loaded(\$extension))->toBeTrue("Missing embedded extension: {\$extension}");
    }
    expect(str_starts_with((new ReflectionClass(Pest\TestSuite::class))->getFileName(), 'phar://'))->toBeFalse();
    expect(is_dir(getcwd() . DIRECTORY_SEPARATOR . 'vendor'))->toBeFalse();
    echo 'Embedded PHP ' . PHP_VERSION . PHP_EOL;
});
PHP);
            $arguments = $this->native ? [$this->binary] : [PHP_BINARY, $this->binary];
            $process = new Process([...$arguments, 'tests', '--no-ansi'], $fixture->root, ['PATH' => '', 'XDEBUG_MODE' => 'off']);
            $process->setTimeout(120);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertStringContainsString('release runtime requirements', $process->getOutput());
            fwrite(STDOUT, $process->getOutput());
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_reports_the_tag_version_from_the_packaged_cli(): void
    {
        $version = getenv('ARIX_RELEASE_VERSION');
        $this->assertNotFalse($version, 'ARIX_RELEASE_VERSION is required for release integration.');
        $this->assertNotSame('', $version);
        $arguments = $this->native ? [$this->binary] : [PHP_BINARY, $this->binary];
        $process = new Process([...$arguments, '--version', '--no-ansi'], env: ['PATH' => '', 'XDEBUG_MODE' => 'off']);
        $process->mustRun();
        Release::reportedVersion($process->getOutput(), $version);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_checks_and_fixes_packaged_application_code_without_system_php(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            if (! $this->native) {
                $pint = 'phar://' . $this->binary . '/vendor/laravel/pint/builds/pint';
                $this->assertFileExists($pint);
                $this->assertGreaterThan(0, filesize($pint));
            }

            $code = "<?php\ndeclare(strict_types=1);\nclass Controller{public function index(){return 1;}}\n";
            $fixture->write('app/Controller.php', $code);
            $this->runFormat($fixture, ['--test'], 1);
            $this->assertSame($code, file_get_contents($fixture->path('app/Controller.php')));
            $this->runFormat($fixture, [], 0);
            $formatted = file_get_contents($fixture->path('app/Controller.php'));
            $this->assertNotSame($code, $formatted);
            $this->runFormat($fixture, ['--test'], 0);
            $this->assertSame($formatted, file_get_contents($fixture->path('app/Controller.php')));
            $this->assertDirectoryDoesNotExist($fixture->path('vendor'));
            $this->assertSame([], glob($fixture->path('temporary files/arix-format-runtime-*')));
            $this->assertSame([], glob($fixture->path('arix-format-*')));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_respects_packaged_format_rules_and_exclusions(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('.arix-format.json', '{"rules":{"no_unused_imports":false}}');
            $fixture->write('app/Controller.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App;
use RuntimeException;
class Controller {}
PHP);
            $this->runFormat($fixture, [], 0);
            $code = "<?php\ndeclare(strict_types=1);\necho( 'excluded' );\n";
            $fixture->write('app/views/Layout.arix.php', $code);
            $fixture->write('core/Excluded.php', $code);
            $fixture->write('outside.php', $code);
            $contents = file_get_contents($fixture->path('app/Controller.php'));
            $this->assertStringContainsString('use RuntimeException;', $contents);
            $this->runFormat($fixture, ['--test'], 0);
            $fixture->write('custom rules.json', '{"rules":{"no_unused_imports":true}}');
            $this->runFormat($fixture, ['--test', '--config=' . $fixture->path('custom rules.json')], 1);
            $this->assertSame($contents, file_get_contents($fixture->path('app/Controller.php')));

            foreach (['app/views/Layout.arix.php', 'core/Excluded.php', 'outside.php'] as $relative) {
                $this->assertSame($code, file_get_contents($fixture->path($relative)));
            }

            $arguments = $this->native ? [$this->binary] : [PHP_BINARY, $this->binary];
            $invalid = new Process([...$arguments, 'format', '--test', '--path=' . $fixture->path('missing')], env: ['PATH' => '', 'XDEBUG_MODE' => 'off']);
            $invalid->run();
            $this->assertSame(2, $invalid->getExitCode(), $invalid->getOutput() . $invalid->getErrorOutput());
        } finally {
            $fixture->cleanup();
        }
    }

    private function runFormat(ApplicationTestingFixture $fixture, array $options, int $expected): void
    {
        $temporary = $fixture->path('temporary files');

        if (! is_dir($temporary)) {
            mkdir($temporary, 0700);
        }

        $arguments = $this->native ? [$this->binary] : [PHP_BINARY, $this->binary];
        $process = new Process([...$arguments, 'format', '--path=' . $fixture->root, '--no-ansi', ...$options], dirname(__DIR__, 3), [
            'PATH' => '',
            'XDEBUG_MODE' => 'off',
            'TMPDIR' => $temporary,
            'TMP' => $temporary,
            'TEMP' => $temporary,
        ]);
        $process->setTimeout(120);
        $process->run();
        $this->assertSame($expected, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
