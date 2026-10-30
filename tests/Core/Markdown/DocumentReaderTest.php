<?php

declare(strict_types=1);

namespace Tests\Core\Markdown;

use Arix\Markdown\DocumentReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentReaderTest extends TestCase
{
    #[Test]
    public function it_rejects_paths_outside_the_root(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures';
        $reader = new DocumentReader($root);

        $this->expectException(RuntimeException::class);
        $reader->read('../secret.md');
    }

    #[Test]
    public function it_reads_a_confined_markdown_file(): void
    {
        $root = realpath(sys_get_temp_dir());
        $name = 'arix-markdown-' . bin2hex(random_bytes(8)) . '.md';
        file_put_contents($root . DIRECTORY_SEPARATOR . $name, '# Hello');

        try {
            $this->assertSame('# Hello', new DocumentReader($root)->read($name));
        } finally {
            unlink($root . DIRECTORY_SEPARATOR . $name);
        }
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_invalid_names(string $name): void
    {
        $this->expectException(RuntimeException::class);
        new DocumentReader(realpath(sys_get_temp_dir()))->read($name);
    }

    public static function invalidNames(): array
    {
        return [[''], ['/absolute.md'], ['a/../b.md'], ['a\\b.md'], ['a.php'], ['a.md/child.md']];
    }

    #[Test]
    public function it_reads_a_document_through_a_linked_root(): void
    {
        $root = realpath(sys_get_temp_dir());
        $name = 'arix-markdown-link-' . bin2hex(random_bytes(8)) . '.md';
        $directory = $root . DIRECTORY_SEPARATOR . 'arix-markdown-' . bin2hex(random_bytes(8));
        $link = "{$directory}-link";
        mkdir($directory);
        file_put_contents($directory . DIRECTORY_SEPARATOR . $name, 'content');
        symlink($directory, $link);

        try {
            $this->assertSame('content', new DocumentReader($link)->read($name));
        } finally {
            unlink($link);
            unlink($directory . DIRECTORY_SEPARATOR . $name);
            rmdir($directory);
        }
    }
}
