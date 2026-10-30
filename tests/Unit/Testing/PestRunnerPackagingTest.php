<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

class PestRunnerPackagingTest extends TestCase
{
    #[Test]
    public function it_runs_bundled_pest_and_removes_its_private_runtime(): void
    {
        $fixture = new ApplicationTestingFixture();
        $project = dirname(__DIR__, 3);

        try {
            $fixture->write('build.php', <<<'PHP'
<?php
declare(strict_types=1);
$project = $argv[1];
$archive = new Phar(__DIR__ . '/testing.phar');
$archive->startBuffering();
$archive->addFile($project . '/arix', 'arix');
foreach (glob($project . '/app/Testing/*.php') as $file) {
    $archive->addFile($file, 'app/Testing/' . basename($file));
}
$archive->buildFromIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project . '/vendor', FilesystemIterator::SKIP_DOTS)), $project);
$archive->addFromString('vendor/shutdown-marker.php', '<?php return "shutdown-ok";');
$archive->setStub("<?php Phar::mapPhar('testing.phar'); require 'phar://testing.phar/arix'; __HALT_COMPILER();");
$archive->stopBuffering();
PHP);
            $builder = new Process([PHP_BINARY, '-d', 'phar.readonly=0', $fixture->path('build.php'), $project]);
            $builder->setTimeout(300);
            $builder->mustRun();
            $fixture->write('tests/RuntimeTest.php', <<<'PHP'
<?php
test('private physical runtime', function () {
    $file = (new ReflectionClass(Pest\TestSuite::class))->getFileName();
    expect(str_starts_with($file, 'phar://'))->toBeFalse();
    $root = dirname($file, 5);
    expect(is_dir($root))->toBeTrue();
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(fileperms($root) & 0777)->toBe(0700);
    }
    file_put_contents(getcwd() . '/runtime-path.txt', $root);
    register_shutdown_function(function () use ($root) {
        $value = require $root . '/vendor/shutdown-marker.php';
        file_put_contents(getcwd() . '/shutdown-marker.txt', $value);
    });
});
PHP);
            $process = $fixture->worker($fixture->path('testing.phar'));
            $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertStringContainsString('private physical runtime', $process->getOutput());
            $runtime = file_get_contents($fixture->path('runtime-path.txt'));
            $this->assertDirectoryDoesNotExist($runtime);
            $this->assertSame('shutdown-ok', file_get_contents($fixture->path('shutdown-marker.txt')));

            $fixture->write('tests/FailureTest.php', '<?php test("packaged failure", fn () => expect(1)->toBe(2));');
            $process = $fixture->worker($fixture->path('testing.phar'));
            $this->assertSame(1, $process->getExitCode());
            $this->assertDirectoryDoesNotExist(file_get_contents($fixture->path('runtime-path.txt')));
        } finally {
            $fixture->cleanup();
        }
    }
}
