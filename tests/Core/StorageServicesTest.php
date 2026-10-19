<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Storage\Storage;
use Arix\Storage\StorageServices;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

class StorageServicesTest extends TestCase
{
    private function createRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix_storage_services_test_' . uniqid('', true);

        mkdir($root, recursive: true);

        return realpath($root);
    }

    #[Test]
    public function it_registers_storage_service(): void
    {
        $root = $this->createRoot();
        $container = new Container();

        StorageServices::register($container, $root);

        $this->assertTrue($container->has(Storage::class));
        $this->assertInstanceOf(Storage::class, $container->get(Storage::class));
    }
}
