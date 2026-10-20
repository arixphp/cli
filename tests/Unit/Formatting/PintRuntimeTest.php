<?php

declare(strict_types=1);

namespace Tests\Unit\Formatting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

class PintRuntimeTest extends TestCase
{
    private ApplicationTestingFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new ApplicationTestingFixture();
        $this->fixture->write('rules.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_materializes_only_pint_as_a_physical_file(): void
    {
        $stub = $this->pintStub();
        $archive = $this->buildArchive($stub);
        $process = $this->worker($archive);
        $process->mustRun();
        $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertFalse(str_starts_with($data['path'], 'phar://'));
        $this->assertSame(hash('sha256', $stub), $data['hash']);
        $this->assertSame(['pint.phar'], $data['files']);
        $this->assertDirectoryDoesNotExist($this->fixture->path('vendor'));

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertSame(0700, $data['permissions']);
        }

        $this->assertFileDoesNotExist($data['path']);
        $this->assertDirectoryDoesNotExist(dirname($data['path']));
    }

    #[Test]
    public function it_cleans_the_private_runtime_after_worker_exit(): void
    {
        $archive = $this->buildArchive($this->pintStub());

        foreach ([0, 1] as $status) {
            $process = $this->worker($archive, $status);
            $process->run();
            $this->assertSame($status, $process->getExitCode(), $process->getErrorOutput());
            $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFileDoesNotExist($data['path']);
            $this->assertDirectoryDoesNotExist(dirname($data['path']));
        }
    }

    #[Test]
    public function it_rejects_missing_bundled_pint(): void
    {
        $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-format-runtime-*');
        $process = $this->worker($this->buildArchive(null));
        $process->run();
        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Pint is not available', $process->getErrorOutput());
        $this->assertSame($before, glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-format-runtime-*'));
    }

    #[Test]
    public function it_keeps_concurrent_worker_runtimes_separate(): void
    {
        $archive = $this->buildArchive($this->pintStub());
        $first = $this->worker($archive, pause: true);
        $second = $this->worker($archive, pause: true);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $one = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR)['path'];
        $two = json_decode($second->getOutput(), true, flags: JSON_THROW_ON_ERROR)['path'];
        $this->assertNotSame(dirname($one), dirname($two));
        $this->assertDirectoryDoesNotExist(dirname($one));
        $this->assertDirectoryDoesNotExist(dirname($two));
    }

    private function worker(string $archive, int $status = 0, bool $pause = false): Process
    {

        return new Process([PHP_BINARY, $archive, '--arix-format-worker', $this->fixture->root, $this->fixture->path('rules.json'), 'check'], $this->fixture->root, [
            'XDEBUG_MODE' => 'off',
            'PINT_TEST_STATUS' => (string) $status,
            'PINT_TEST_PAUSE' => $pause ? '1' : '0',
        ]);
    }

    private function pintStub(): string
    {

        return <<<'PHP'
<?php
declare(strict_types=1);
echo json_encode([
    'path' => __FILE__,
    'hash' => hash_file('sha256', __FILE__),
    'files' => array_values(array_diff(scandir(__DIR__), ['.', '..'])),
    'permissions' => fileperms(__DIR__) & 0777,
]);
if (getenv('PINT_TEST_PAUSE') === '1') {
    usleep(500000);
}
exit((int) getenv('PINT_TEST_STATUS'));
PHP;
    }

    private function buildArchive(string|null $stub): string
    {
        $this->fixture->write('build.php', <<<'PHP'
<?php
declare(strict_types=1);
$archive = new Phar($argv[1]);
$root = $argv[2];
foreach (['arix', 'app/Formatting/worker.php', 'app/Formatting/PintRuntime.php'] as $relative) {
    $archive->addFile($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $relative);
}
if (is_file($argv[3])) {
    $archive->addFile($argv[3], 'vendor/laravel/pint/builds/pint');
}
$archive->setStub('<?php Phar::mapPhar(); require "phar://" . __FILE__ . "/arix"; __HALT_COMPILER();');
PHP);

        if ($stub !== null) {
            $this->fixture->write('pint-stub.php', $stub);
        }

        $archive = $this->fixture->path('distribution.phar');
        $builder = new Process([PHP_BINARY, '-d', 'phar.readonly=0', $this->fixture->path('build.php'), $archive, dirname(__DIR__, 3), $this->fixture->path('pint-stub.php')], env: ['XDEBUG_MODE' => 'off']);
        $builder->mustRun();

        return $archive;
    }
}
