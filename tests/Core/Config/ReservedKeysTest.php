<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Arix\Config\ConfigServices;
use Arix\Config\ReservedKeys;
use Arix\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReservedKeysTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-reserved-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('reservedDeclarations')]
    public function it_rejects_reserved_configuration_keys(string $file, string $body, string $expected): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . $file, $body);
        $container = new Container();

        try {
            ConfigServices::register($container, $this->directory);
            $this->fail('Reserved configuration keys must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
        }
    }

    public static function reservedDeclarations(): array
    {
        return [
            'cache path' => ['cache.php', "<?php return ['path' => 'storage/cache'];", "reserved configuration key 'cache.path'"],
            'cache path absolute' => ['cache.php', "<?php return ['path' => '/var/www/html'];", "reserved configuration key 'cache.path'"],
            'schedule log' => ['schedule.php', "<?php return ['log' => 'storage/logs/cron.log'];", "reserved configuration key 'schedule.log'"],
        ];
    }

    #[Test]
    public function it_accepts_the_remaining_cache_and_schedule_keys(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'cache.php', "<?php return ['version' => '7', 'templates' => ['interval' => 60]];");
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'schedule.php', "<?php return ['timezone' => 'UTC', 'grace' => 60];");
        $container = new Container();

        $repository = ConfigServices::register($container, $this->directory);

        $this->assertSame('7', $repository->get('cache.version'));
        $this->assertSame(60, $repository->get('cache.templates.interval'));
        $this->assertSame('UTC', $repository->get('schedule.timezone'));
        $this->assertSame(60, $repository->get('schedule.grace'));
    }

    #[Test]
    public function it_only_applies_to_its_own_sections(): void
    {
        ReservedKeys::assert('database', ['path' => 'ignored'], 'database.php');
        ReservedKeys::assert('app', ['log' => 'ignored'], 'app.php');

        $this->assertTrue(true);
    }
}
