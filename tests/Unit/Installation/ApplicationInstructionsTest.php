<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\ApplicationInstructions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ApplicationInstructionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $temporary = realpath(sys_get_temp_dir());

        $this->assertNotFalse($temporary);

        $this->root = $temporary . DIRECTORY_SEPARATOR . 'arix-instructions-' . bin2hex(random_bytes(8));

        mkdir($this->root . DIRECTORY_SEPARATOR . 'application', 0700, true);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'source.md', "# Instructions\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    #[Test]
    public function it_writes_the_instructions_into_the_application(): void
    {
        (new ApplicationInstructions($this->source()))->write($this->application());

        $this->assertSame("# Instructions\n", file_get_contents($this->application() . DIRECTORY_SEPARATOR . 'AGENTS.md'));
    }

    #[Test]
    public function it_replaces_the_instructions_shipped_by_the_skeleton(): void
    {
        file_put_contents($this->application() . DIRECTORY_SEPARATOR . 'AGENTS.md', 'Skeleton instructions.');

        (new ApplicationInstructions($this->source()))->write($this->application());

        $this->assertSame("# Instructions\n", file_get_contents($this->application() . DIRECTORY_SEPARATOR . 'AGENTS.md'));
    }

    #[Test]
    public function it_refuses_to_replace_linked_instructions(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are unavailable.');
        }

        symlink($this->source(), $this->application() . DIRECTORY_SEPARATOR . 'AGENTS.md');

        $this->expectException(RuntimeException::class);

        (new ApplicationInstructions($this->source()))->write($this->application());
    }

    #[Test]
    public function it_fails_when_the_source_is_missing(): void
    {
        $this->expectException(RuntimeException::class);

        (new ApplicationInstructions($this->root . DIRECTORY_SEPARATOR . 'absent.md'))->write($this->application());
    }

    private function source(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'source.md';
    }

    private function application(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'application';
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        rmdir($path);
    }
}
