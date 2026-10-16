<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\RuntimeArchive;
use App\Development\RuntimeDownloader;
use App\Development\RuntimeManifest;
use App\Development\RuntimePlatform;
use App\Development\RuntimeProbe;
use App\Development\RuntimeStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InstallationTransport;
use Tests\Support\NewApplicationFixture;

class RuntimeStoreTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_installs_once_reuses_offline_and_rejects_tampering(): void
    {
        $platform = RuntimePlatform::detect();
        $manifest = $this->fixture->write('manifest.json', json_encode(['version' => 'v1.13.0', 'targets' => [$platform => ['url' => 'https://github.com/php/frankenphp/releases/download/v1.13.0/test', 'sha256' => hash('sha256', 'binary'), 'max_bytes' => 100, 'archive' => false, 'executable' => 'frankenphp']]]));

        $probe = new class() extends RuntimeProbe {
            public function verify(string $executable, bool $reload = true): void {}
        };

        $store = new RuntimeStore(new RuntimeManifest($manifest), new RuntimeDownloader(new InstallationTransport([['status' => 200, 'body' => 'binary']])), new RuntimeArchive(), $probe, $this->fixture->path('cache'));
        $binary = $store->resolve(null);

        $this->assertSame('binary', file_get_contents($binary));
        $this->assertSame($binary, $store->resolve(null));

        file_put_contents($binary, 'tampered');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('integrity');

        $store->resolve(null);
    }

    #[Test]
    public function it_retains_windows_runtime_files(): void
    {
        $archive = $this->fixture->archive(['frankenphp.exe' => 'exe', 'php/php.exe' => 'php', 'php/php8ts.dll' => 'dll']);

        mkdir($this->fixture->path('unpacked'));

        new RuntimeArchive()->extract($archive, $this->fixture->path('unpacked'), ['max_unpacked_bytes' => 100]);

        $this->assertSame('dll', file_get_contents($this->fixture->path('unpacked/php/php8ts.dll')));
    }

    #[Test]
    #[DataProvider('unsafeEntries')]
    public function it_rejects_unsafe_runtime_archives(string $entry): void
    {
        $archive = $this->fixture->archive([$entry => 'bad']);

        mkdir($this->fixture->path('unpacked'));

        $this->expectException(RuntimeException::class);

        new RuntimeArchive()->extract($archive, $this->fixture->path('unpacked'), ['max_unpacked_bytes' => 100]);
    }

    public static function unsafeEntries(): array
    {
        return [['../outside'], ['/outside'], ['C:/outside'], ['dir\\outside'], ['CON'], ['dir/../file'], ['file.']];
    }

    #[Test]
    public function it_rejects_unsupported_native_architectures(): void
    {
        $this->expectException(RuntimeException::class);

        RuntimePlatform::normalize('Windows', 'arm64');
    }
}
