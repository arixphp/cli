<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\FileScheduleLock;
use Arix\Storage\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function bin2hex;
use function random_bytes;

class FileScheduleLockTest extends TestCase
{
    private string $directory;

    private FileScheduleLock $lock;

    protected function setUp(): void
    {
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-lock-' . bin2hex(random_bytes(8));

        if (! mkdir($this->directory, 0755, true)) {
            throw new RuntimeException('Cannot create the test directory.');
        }

        $this->lock = new FileScheduleLock(new Storage($this->directory));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    #[Test]
    public function it_acquires_and_completes_an_occurrence(): void
    {
        $token = $this->lock->acquire('report:daily', '202610190000');

        $this->assertNotNull($token);
        $this->assertFalse($this->lock->isCompleted('report:daily', '202610190000'));

        $this->lock->complete('report:daily', '202610190000', $token);

        $this->assertTrue($this->lock->isCompleted('report:daily', '202610190000'));
        $this->assertFalse($this->lock->isCompleted('report:daily', '202610190100'));
    }

    #[Test]
    public function it_does_not_acquire_an_occurrence_that_is_already_completed(): void
    {
        $token = $this->lock->acquire('report:daily', '202610190000');
        $this->lock->complete('report:daily', '202610190000', $token);

        $this->assertNull($this->lock->acquire('report:daily', '202610190000'));
    }

    #[Test]
    public function it_releases_without_completing_so_the_occurrence_can_be_retried(): void
    {
        $token = $this->lock->acquire('report:daily', '202610190000');
        $this->lock->release('report:daily', '202610190000', $token);

        $this->assertFalse($this->lock->isCompleted('report:daily', '202610190000'));
        $this->assertNotNull($this->lock->acquire('report:daily', '202610190000'));
    }

    #[Test]
    public function it_excludes_two_locks_of_the_same_event(): void
    {
        $first = new FileScheduleLock(new Storage($this->directory));
        $second = new FileScheduleLock(new Storage($this->directory));

        $this->assertNotNull($first->acquire('report:daily', '202610190000'));
        $this->assertNull($second->acquire('report:daily', '202610190000'));
    }

    #[Test]
    public function it_fails_when_the_lock_directory_cannot_be_created(): void
    {
        $storage = new Storage($this->directory);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'blocked', 'not a directory');
        $lock = new FileScheduleLock($storage, 'blocked');

        $this->expectException(RuntimeException::class);

        $lock->acquire('report:daily', '202610190000');
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
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->remove($path . DIRECTORY_SEPARATOR . $entry);
        }

        rmdir($path);
    }
}
