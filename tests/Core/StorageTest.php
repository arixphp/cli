<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Storage\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

class StorageTest extends TestCase
{
    #[Test]
    public function it_uses_a_storage_root_without_rejecting_linked_ancestors(): void
    {
        $root = $this->createRoot();
        $link = "{$root}-link";
        symlink($root, $link);

        try {
            $storage = new Storage($link);
            $storage->put('example.txt', 'content');
            $this->assertSame('content', $storage->get('example.txt'));
        } finally {
            unlink($link);
            if (is_file($root . DIRECTORY_SEPARATOR . 'example.txt')) {
                unlink($root . DIRECTORY_SEPARATOR . 'example.txt');
            }
            rmdir($root);
        }
    }

    private function createRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix_storage_test_' . uniqid('', true);

        mkdir($root, recursive: true);

        return realpath($root);
    }

    #[Test]
    public function it_writes_and_reads_a_file(): void
    {
        $root = $this->createRoot();
        $storage = new Storage($root);

        $storage->put('sub/file.txt', 'contenido');

        $this->assertTrue($storage->has('sub/file.txt'));
        $this->assertSame('contenido', $storage->get('sub/file.txt'));
        $this->assertSame($root . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'file.txt', $storage->path('sub/file.txt'));

        $storage->delete('sub/file.txt');

        $this->assertFalse($storage->has('sub/file.txt'));
        $this->assertTrue($storage->missing('sub/file.txt'));
    }

    #[Test]
    public function it_appends_and_prepends_to_existing_file(): void
    {
        $root = $this->createRoot();
        $storage = new Storage($root);

        $storage->put('notes.txt', 'mundo');
        $storage->append('notes.txt', ' cruel');
        $this->assertSame('mundo cruel', $storage->get('notes.txt'));

        $storage->prepend('notes.txt', 'hola ');
        $this->assertSame('hola mundo cruel', $storage->get('notes.txt'));
    }

    #[Test]
    public function it_rejects_directory_traversal(): void
    {
        $root = $this->createRoot();
        $storage = new Storage($root);

        $this->expectException(InvalidArgumentException::class);
        $storage->put('../escape.txt', 'x');
    }

    #[Test]
    public function it_rejects_absolute_paths(): void
    {
        $root = $this->createRoot();
        $storage = new Storage($root);

        $this->expectException(InvalidArgumentException::class);
        $storage->put('/etc/passwd', 'x');
    }

    #[Test]
    public function it_rejects_missing_parent_for_get(): void
    {
        $root = $this->createRoot();
        $storage = new Storage($root);

        $this->expectException(InvalidArgumentException::class);
        $storage->get('nope/dir/file.txt');
    }

    #[Test]
    public function it_rejects_nonexistent_storage_root(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Storage(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix_does_not_exist_' . uniqid('', true));
    }

    #[Test]
    public function it_creates_parent_directories_on_put(): void
    {
        $root = $this->createRoot();
        $storage = new Storage($root);

        $storage->put('a/b/c/file.txt', 'profundo');

        $this->assertSame('profundo', $storage->get('a/b/c/file.txt'));
    }

    public static function tearDownAfterClass(): void
    {
        // No-op: test directories live under the system temp dir.
    }
}
