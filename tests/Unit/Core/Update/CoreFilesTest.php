<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\CoreUpdateFixture;

class CoreFilesTest extends TestCase
{
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
