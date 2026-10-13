<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PackagedNewCommandTest extends TestCase
{
    private string $entry;

    protected function setUp(): void
    {
        $this->entry = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'arix';
    }

    #[Test]
    public function it_registers_the_new_command_in_the_source_entry(): void
    {
        $process = new Process([PHP_BINARY, $this->entry, 'new', '--help', '--no-ansi'], null, ['XDEBUG_MODE' => 'off']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('Parent directory', $process->getOutput());
    }

    #[Test]
    public function it_skips_phar_verification_when_no_build_exists(): void
    {
        $candidates = [
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'builds' . DIRECTORY_SEPARATOR . 'arix.phar',
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'arix.phar',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $process = new Process([PHP_BINARY, $candidate, 'new', '--help', '--no-ansi'], null, ['XDEBUG_MODE' => 'off']);
                $process->run();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
                $this->assertStringContainsString('Parent directory', $process->getOutput());

                return;
            }
        }

        $this->markTestSkipped('No PHAR build available. Build with app:build to verify packaged distribution.');
    }

    #[Test]
    public function it_proves_native_platform_requirements_without_network_or_php_in_path(): void
    {
        $binary = getenv('ARIX_TEST_EXECUTABLE');

        if ($binary === false || $binary === '') {
            $this->markTestSkipped('Set ARIX_TEST_EXECUTABLE to a PHPacker build for native integration tests.');
        }

        $process = new Process([$binary, 'new', '../escape', '--no-ansi'], sys_get_temp_dir(), ['PATH' => '']);
        $process->setTimeout(120);
        $process->run();
        $this->assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        $this->assertStringContainsString('Invalid application name', $process->getOutput() . $process->getErrorOutput());
    }
}
