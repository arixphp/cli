<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use App\Testing\TestProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

class TestProcessTest extends TestCase
{
    #[Test]
    public function it_reexecutes_a_self_contained_binary_without_a_php_command(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('standalone executable', "#!/bin/sh\nprintf '%s\\n' \"\$@\"\n");
            chmod($fixture->path('standalone executable'), 0755);
            $output = '';
            $status = (new TestProcess())->run($fixture->root, $fixture->path('standalone executable'), false, static function (string $type, string $buffer) use (&$output): void {
                $output .= $buffer;
            }, true);
            $this->assertSame(0, $status, $output);
            $this->assertSame("--arix-tests-worker\n{$fixture->root}\nnever\n", $output);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_streams_channels_preserves_paths_and_propagates_exit_status(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('entry with spaces.php', <<<'PHP'
<?php
echo json_encode([$argv[1], $argv[2], $argv[3], getcwd()]);
fwrite(STDERR, 'worker diagnostic');
exit(7);
PHP);
            $streams = [Process::OUT => '', Process::ERR => ''];
            $status = (new TestProcess())->run($fixture->root, $fixture->path('entry with spaces.php'), false, static function (string $type, string $buffer) use (&$streams): void {
                $streams[$type] .= $buffer;
            });
            $this->assertSame(7, $status);
            $this->assertSame(['--arix-tests-worker', $fixture->root, 'never', $fixture->root], json_decode($streams[Process::OUT], true));
            $this->assertStringContainsString('worker diagnostic', $streams[Process::ERR]);
            $this->assertStringNotContainsString('worker diagnostic', $streams[Process::OUT]);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_reports_a_missing_worker_without_shell_execution(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $status = (new TestProcess())->run($fixture->root, $fixture->path('missing.php'), true, static function (): void {});
            $this->assertNotSame(0, $status);
        } finally {
            $fixture->cleanup();
        }
    }
}
