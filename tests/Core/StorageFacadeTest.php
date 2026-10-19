<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Facades\Storage;
use Arix\Storage\Storage as StorageService;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

class StorageFacadeTest extends TestCase
{
    private function createRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix_storage_facade_test_' . uniqid('', true);

        mkdir($root, recursive: true);

        return realpath($root);
    }

    #[Test]
    public function it_delegates_to_registered_storage_service(): void
    {
        $root = $this->createRoot();
        $container = new Container();
        $container->instance(StorageService::class, new StorageService($root));

        ContainerContext::run($container, function () use ($root): void {
            Storage::put('facade.txt', 'hola');

            $this->assertTrue(Storage::has('facade.txt'));
            $this->assertSame('hola', Storage::get('facade.txt'));
            $this->assertSame($root . DIRECTORY_SEPARATOR . 'facade.txt', Storage::path('facade.txt'));

            Storage::append('facade.txt', ' mundo');
            $this->assertSame('hola mundo', Storage::get('facade.txt'));

            Storage::delete('facade.txt');
            $this->assertFalse(Storage::has('facade.txt'));
            $this->assertTrue(Storage::missing('facade.txt'));
        });
    }

    #[Test]
    public function it_throws_without_active_container(): void
    {
        $this->expectException(LogicException::class);
        Storage::put('x.txt', 'y');
    }
}
