<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ReloadClientTest extends TestCase
{
    #[Test]
    public function it_reloads_only_for_real_changes_and_subscribes_once(): void
    {
        $node = new ExecutableFinder()->find('node');

        if ($node === null) {
            $this->markTestSkipped('Optional client test requires Node, never a product runtime dependency.');
        }

        $base = dirname(__DIR__, 3);
        $process = new Process([$node, $base . DIRECTORY_SEPARATOR . 'tests/Fixtures/Development/reload-client.mjs', $base . DIRECTORY_SEPARATOR . 'resources/js/dev-reload.js']);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Reload client behavior passed', $process->getOutput());
    }
}
