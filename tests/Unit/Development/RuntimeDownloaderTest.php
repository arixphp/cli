<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\RuntimeDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InstallationTransport;
use Tests\Support\NewApplicationFixture;

class RuntimeDownloaderTest extends TestCase
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

    private function target(): array
    {
        return ['url' => 'https://github.com/php/frankenphp/releases/download/v1.13.0/runtime', 'max_bytes' => 100, 'sha256' => hash('sha256', 'runtime')];
    }

    #[Test]
    public function it_downloads_and_checks_integrity_through_a_release_redirect(): void
    {
        $transport = new InstallationTransport([['status' => 302, 'body' => '', 'location' => 'https://release-assets.githubusercontent.com/github-production-release-asset/123'], ['status' => 200, 'body' => 'runtime']]);

        new RuntimeDownloader($transport)->download($this->target(), $this->fixture->path('download'));

        $this->assertSame('runtime', file_get_contents($this->fixture->path('download')));
    }

    #[Test]
    #[DataProvider('badDownloads')]
    public function it_rejects_corrupt_or_untrusted_downloads(array $response): void
    {
        $this->expectException(RuntimeException::class);

        new RuntimeDownloader(new InstallationTransport([$response]))->download($this->target(), $this->fixture->path('download'));
    }

    public static function badDownloads(): array
    {
        return [[['status' => 200, 'body' => 'corrupt']], [['status' => 200, 'body' => '']], [['status' => 200, 'body' => str_repeat('x', 101)]], [['status' => 404, 'body' => 'missing']], [['status' => 302, 'body' => '', 'location' => 'https://evil.example/runtime']], [['status' => 302, 'body' => '', 'location' => 'http://github.com/runtime']], [['status' => 302, 'body' => '', 'location' => 'https://user@github.com/runtime']]];
    }
}
