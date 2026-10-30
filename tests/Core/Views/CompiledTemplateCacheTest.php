<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use Arix\Views\CompiledTemplateCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CompiledTemplateCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-cache-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0755, true);
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0755);
        $this->remove($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_remembers_compiled_output_without_recompiling(): void
    {
        $cache = new CompiledTemplateCache($this->directory, '1');
        $calls = 0;
        $compile = static function () use (&$calls): string {
            $calls++;

            return '<?php // compiled';
        };

        $first = $cache->remember($this->directory . DIRECTORY_SEPARATOR . 'page.arix.php', 'source-a', $compile);
        $second = $cache->remember($this->directory . DIRECTORY_SEPARATOR . 'page.arix.php', 'source-a', $compile);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
        $this->assertFileExists($first);
        $this->assertSame(['.', '..', basename($first)], array_values(scandir($this->directory)));
    }

    #[Test]
    public function it_keys_by_content_version_and_source(): void
    {
        $cache = new CompiledTemplateCache($this->directory, '1');
        $compile = static fn (): string => '<?php // compiled';

        $original = $cache->remember($this->directory . DIRECTORY_SEPARATOR . 'a.arix.php', 'same', $compile);
        $changed = $cache->remember($this->directory . DIRECTORY_SEPARATOR . 'a.arix.php', 'different', $compile);
        $otherSource = $cache->remember($this->directory . DIRECTORY_SEPARATOR . 'b.arix.php', 'same', $compile);
        $otherVersion = (new CompiledTemplateCache($this->directory, '2'))->remember($this->directory . DIRECTORY_SEPARATOR . 'a.arix.php', 'same', $compile);

        $this->assertNotSame($original, $changed);
        $this->assertNotSame($original, $otherSource);
        $this->assertNotSame($original, $otherVersion);
    }

    #[Test]
    public function it_checks_missing_and_unwritable_directories(): void
    {
        $target = $this->directory . DIRECTORY_SEPARATOR . 'target';
        mkdir($target);
        symlink($target, $this->directory . DIRECTORY_SEPARATOR . 'linked');
        $rejected = 0;

        $this->assertSame($target, new CompiledTemplateCache($this->directory . DIRECTORY_SEPARATOR . 'linked', '1')->directory());

        try {
            new CompiledTemplateCache($this->directory . DIRECTORY_SEPARATOR . 'missing', '1');
        } catch (RuntimeException) {
            $rejected++;
        }

        chmod($this->directory, 0555);

        if (is_writable($this->directory)) {
            $this->assertSame(1, $rejected);
            $this->markTestSkipped('Missing directory was checked; this filesystem/user does not enforce POSIX write permissions.');
        }

        try {
            (new CompiledTemplateCache($this->directory, '1'))->remember('source', 'code', static fn (): string => 'x');
        } catch (RuntimeException) {
            $rejected++;
        }

        $this->assertSame(2, $rejected);
    }

    #[Test]
    public function it_rejects_foreign_paths_and_leaves_no_temp_files(): void
    {
        $cache = new CompiledTemplateCache($this->directory, '1');

        try {
            $cache->write($this->directory . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'evil.php', 'x');
            $this->fail('Foreign cache paths must be rejected.');
        } catch (RuntimeException) {
        }

        $path = $cache->path('source', 'code');
        $cache->write($path, '<?php // compiled');

        $this->assertSame('<?php // compiled', file_get_contents($path));
        $this->assertCount(1, array_diff(scandir($this->directory), ['.', '..']));
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }

            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
