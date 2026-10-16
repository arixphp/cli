<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\CaddyConfiguration;
use App\Development\DevOptions;
use App\Development\DevServer;
use App\Development\DevSession;
use App\Development\RuntimeStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewApplicationFixture;

class DevSessionTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();

        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php'] as $file) {
            $this->fixture->write($file, '<?php');
        }
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_does_not_start_a_server_when_cancelled_before_startup(): void
    {
        $server = new DevServer(new CaddyConfiguration(dirname(__DIR__, 3)));
        $server->cancel();
        $code = $server->run('/missing/frankenphp', DevOptions::fromInput([], $this->fixture->root), static function (): void {});

        $this->assertSame(130, $code);
    }

    #[Test]
    public function it_preserves_cancellation_that_arrives_during_runtime_provisioning(): void
    {
        $server = new DevServer(new CaddyConfiguration(dirname(__DIR__, 3)));
        $runtime = new class() extends RuntimeStore
        {
            public DevSession|null $session = null;

            public function __construct() {}

            public function resolve(string|null $override, bool $reload = true, callable|null $progress = null): string
            {
                $this->session?->cancel();

                return '/missing/frankenphp';
            }
        };

        $session = new DevSession($runtime, $server);
        $runtime->session = $session;
        $code = $session->run(DevOptions::fromInput([], $this->fixture->root), static function (): void {});
        $this->assertSame(130, $code);
    }
}
