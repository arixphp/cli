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
            $extensions = ['mbstring'];

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
}
