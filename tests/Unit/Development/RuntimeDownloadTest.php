<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\RuntimeArchive;
use App\Development\RuntimeDownloader;
use App\Development\RuntimeManifest;
use App\Development\RuntimeProbe;
use App\Development\RuntimeStore;
use App\Installation\CurlTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;

class RuntimeDownloadTest extends TestCase
{
    #[Test]
    public function it_downloads_the_pinned_native_runtime_and_runs_server_integration(): void
    {
        if (getenv('ARIX_DEV_TEST_DOWNLOAD') !== '1') {
            $this->markTestSkipped('Explicit network lane: ARIX_DEV_TEST_DOWNLOAD=1.');
        }

        $fixture = new NewApplicationFixture();
        $base = dirname(__DIR__, 3);

        try {
            $store = new RuntimeStore(new RuntimeManifest($base . DIRECTORY_SEPARATOR . 'resources/dev/frankenphp.json'), new RuntimeDownloader(new CurlTransport()), new RuntimeArchive(), new RuntimeProbe(), $fixture->path('cache'));
            $runtime = $store->resolve(null);

            $this->assertSame($runtime, $store->resolve(null));

            $suite = new Process([PHP_BINARY, 'vendor/bin/pest', 'tests/Unit/Development/CaddyConfigurationTest.php', 'tests/Unit/Development/DevServerTest.php', 'tests/Unit/Development/RuntimeCompatibilityTest.php'], $base, ['ARIX_DEV_TEST_RUNTIME' => $runtime, 'ARIX_DEV_TEST_DOWNLOAD' => '0', 'XDEBUG_MODE' => 'off'], timeout: 120);
            $suite->run();

            $this->assertSame(0, $suite->getExitCode(), $suite->getOutput() . $suite->getErrorOutput());
        } finally {
            $fixture->cleanup();
        }
    }
}
