<?php

declare(strict_types=1);

namespace Tests\Unit\Formatting;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

use function array_slice;

class FormatWorkerTest extends TestCase
{
    #[Test]
    public function it_rejects_invalid_internal_arguments(): void
    {
        $entry = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'arix';

        foreach ([[], [__DIR__, __FILE__, 'invalid']] as $arguments) {
            $process = new Process([PHP_BINARY, $entry, '--arix-format-worker', ...$arguments], env: ['XDEBUG_MODE' => 'off']);
            $process->run();
            $this->assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertStringContainsString('Invalid internal format worker arguments.', $process->getErrorOutput());
        }
    }

    #[Test]
    public function it_reports_missing_pint_without_booting_laravel(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $entry = $this->copyWorker($fixture);
            $fixture->write('rules.json', '{}');
            $process = new Process([PHP_BINARY, $entry, '--arix-format-worker', $fixture->root, $fixture->path('rules.json'), 'check'], env: ['XDEBUG_MODE' => 'off']);
            $process->run();
            $this->assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertStringContainsString('Pint is not available in the CLI installation.', $process->getErrorOutput());
            $this->assertSame('', $process->getOutput());
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_runs_pint_in_an_isolated_worker(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $entry = $this->copyWorker($fixture);
            $fixture->write('rules.json', '{}');
            $fixture->write('vendor/laravel/pint/builds/pint', <<<'PHP'
<?php
declare(strict_types=1);
echo json_encode([
    class_exists(App\Commands\Format::class),
    class_exists(Illuminate\Container\Container::class),
    $argv === $_SERVER['argv'],
    $argc === $_SERVER['argc'],
    $argv,
    getcwd(),
]);
fwrite(STDERR, 'isolated Pint');
exit(7);
PHP);
            $process = new Process([PHP_BINARY, $entry, '--arix-format-worker', $fixture->root, $fixture->path('rules.json'), 'check'], env: ['XDEBUG_MODE' => 'off']);
            $process->run();
            $this->assertSame(7, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $data = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([false, false, true, true], array_slice($data, 0, 4));
            $this->assertSame(['--config=' . $fixture->path('rules.json'), '--no-ansi', '--test'], array_slice($data[4], 1));
            $this->assertSame($fixture->root, $data[5]);
            $this->assertSame('isolated Pint', $process->getErrorOutput());
        } finally {
            $fixture->cleanup();
        }
    }

    private function copyWorker(ApplicationTestingFixture $fixture): string
    {
        $root = dirname(__DIR__, 3);

        foreach (['arix', 'app/Formatting/worker.php', 'app/Formatting/PintRuntime.php'] as $relative) {
            $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->assertFileExists($source);
            $fixture->write($relative, file_get_contents($source));
        }

        return $fixture->path('arix');
    }
}
