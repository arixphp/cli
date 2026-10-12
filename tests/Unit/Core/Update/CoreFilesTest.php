<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CoreUpdateFixture;

class CoreFilesTest extends TestCase
{
    #[Test]
    public function it_accepts_the_exact_root_htaccess_path(): void
    {
        CoreFiles::assertRelative('.htaccess');
        CoreFiles::assertRelative('Http/Kernel.php');

        $this->assertSame('core' . DIRECTORY_SEPARATOR . '.htaccess', CoreFiles::local('core', '.htaccess'));
    }

    #[Test]
    #[DataProvider('unsafePaths')]
    public function it_rejects_other_hidden_or_unsafe_paths(string $path): void
    {
        $this->expectException(RuntimeException::class);
        CoreFiles::assertRelative($path);
    }

    public static function unsafePaths(): array
    {

        return [['.HTACCESS'], ['.env'], ['folder/.htaccess'], ['../.htaccess'], ['/.htaccess'], ['folder\\file.php'], ["file\0.php"], ['.htaccess.php']];
    }

    #[Test]
    public function it_inventories_exact_runtime_resource_bytes(): void
    {
        $fixture = new CoreUpdateFixture();

        try {
            $fixture->write('source/.htaccess', "Require all denied\n");
            $fixture->write('source/stubs/error.html', '<html>error</html>');
            $hashes = CoreFiles::inventory($fixture->path('source'), true);

            $this->assertSame([
                '.htaccess' => hash('sha256', "Require all denied\n"),
                'stubs/error.html' => hash('sha256', '<html>error</html>'),
            ], $hashes);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    #[DataProvider('unsupportedResources')]
    public function it_rejects_unapproved_resources(string $path, string $kind): void
    {
        $fixture = new CoreUpdateFixture();

        try {
            if ($kind === 'directory') {
                $fixture->write('source/placeholder.php', '');
                if (! is_dir(dirname($fixture->path("source/{$path}")))) {
                    mkdir(dirname($fixture->path("source/{$path}")), 0700, true);
                }

                mkdir($fixture->path("source/{$path}"));
            } elseif ($kind === 'link') {
                $target = $fixture->write('target.php', 'target');
                mkdir(dirname($fixture->path("source/{$path}")), 0700, true);
                symlink($target, $fixture->path("source/{$path}"));
            } else {
                $fixture->write("source/{$path}", 'unapproved');
            }

            $this->expectException(RuntimeException::class);
            CoreFiles::inventory($fixture->path('source'), true);
        } finally {
            $fixture->cleanup();
        }
    }

    public static function unsupportedResources(): array
    {

        return [['stubs/other.html', 'file'], ['.htaccess.php', 'file'], ['.htaccess', 'link'], ['.htaccess', 'directory'], ['stubs/error.html', 'link'], ['stubs/error.html', 'directory']];
    }

    #[Test]
    public function it_reports_write_failures_without_native_php_warnings(): void
    {
        $fixture = new CoreUpdateFixture();

        try {
            $autoload = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
            $code = <<<'PHP'
require $argv[1];
try {
    App\Core\Update\CoreFiles::write($argv[2].DIRECTORY_SEPARATOR.'missing'.DIRECTORY_SEPARATOR.'file.php', 'bytes');
    exit(1);
} catch (RuntimeException $exception) {
    echo $exception->getMessage();
}
PHP;
            $process = new Process([PHP_BINARY, '-r', $code, $autoload, $fixture->root], env: ['XDEBUG_MODE' => 'off']);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());
            $this->assertStringContainsString('Cannot write', $process->getOutput());
        } finally {
            $fixture->cleanup();
        }
    }
}
