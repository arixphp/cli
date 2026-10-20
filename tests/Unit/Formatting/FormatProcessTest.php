<?php

declare(strict_types=1);

namespace Tests\Unit\Formatting;

use App\Formatting\FormatProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;

class FormatProcessTest extends TestCase
{
    #[Test]
    public function it_reexecutes_a_self_contained_binary_without_php(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('The native release suite covers Windows executable invocation.');
        }

        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('standalone executable', "#!/bin/sh\nprintf '%s\\n' \"\$@\"\n");
            chmod($fixture->path('standalone executable'), 0755);
            $output = '';
            $config = $fixture->path('rules with spaces.json');
            $status = (new FormatProcess())->run($fixture->root, $fixture->path('standalone executable'), $config, true, static function (string $type, string $buffer) use (&$output): void {
                $output .= $buffer;
            }, true);
            $this->assertSame(0, $status, $output);
            $this->assertSame("--arix-format-worker\n{$fixture->root}\n{$config}\ncheck\n", $output);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_preserves_paths_channels_and_exit_status(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('entry with spaces.php', <<<'PHP'
<?php
declare(strict_types=1);
echo json_encode([$argv, getcwd()]);
fwrite(STDERR, 'format diagnostic');
exit(7);
PHP);
            $streams = [Process::OUT => '', Process::ERR => ''];
            $entry = $fixture->path('entry with spaces.php');
            $config = $fixture->path('rules with spaces.json');
            $status = (new FormatProcess())->run($fixture->root, $entry, $config, true, static function (string $type, string $buffer) use (&$streams): void {
                $streams[$type] .= $buffer;
            });
            $this->assertSame(7, $status);
            $this->assertSame([[$entry, '--arix-format-worker', $fixture->root, $config, 'check'], $fixture->root], json_decode($streams[Process::OUT], true));
            $this->assertSame('format diagnostic', $streams[Process::ERR]);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_selects_fix_mode_when_test_is_disabled(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('entry.php', "<?php\ndeclare(strict_types=1);\necho \$argv[4];\n");
            $output = '';
            $status = (new FormatProcess())->run($fixture->root, $fixture->path('entry.php'), $fixture->path('rules.json'), false, static function (string $type, string $buffer) use (&$output): void {
                $output .= $buffer;
            });
            $this->assertSame(0, $status);
            $this->assertSame('fix', $output);
        } finally {
            $fixture->cleanup();
        }
    }
}
