<?php

declare(strict_types=1);

namespace Tests\Feature\Make;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;
use Tests\TestCase;

class MakeCommandTest extends TestCase
{
    #[Test]
    public function it_uses_the_invocation_directory_or_a_relative_path_for_the_target(): void
    {
        $fixture = new NewApplicationFixture();
        $parent = dirname($fixture->root);

        try {
            $fixture->write('.arix.json', '{"format":1,"core_contract":"arix-core-v1"}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->runCommand(['make:controller', 'Home'], $fixture->root);
            $this->assertFileExists($fixture->path('app/controllers/HomeController.php'));

            $relative = basename($fixture->root);
            $this->runCommand(['make:controller', 'Relative', "--path={$relative}"], $parent);
            $this->assertFileExists($fixture->path('app/controllers/RelativeController.php'));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_reports_an_incompatible_target_as_invalid_without_creating_output(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'arix', 'make:controller', 'Post', '--path=' . $fixture->root, '--no-ansi'], dirname(__DIR__, 3), ['XDEBUG_MODE' => 'off']);
            $process->run();

            $this->assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertFileDoesNotExist($fixture->path('app/controllers/PostController.php'));
        } finally {
            $fixture->cleanup();
        }
    }

    /** @param list<string> $arguments */
    private function runCommand(array $arguments, string $cwd): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'arix', ...$arguments, '--no-ansi'], $cwd, ['XDEBUG_MODE' => 'off']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
