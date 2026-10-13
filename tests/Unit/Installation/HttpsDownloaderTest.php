<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\HttpsDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InstallationTransport;
use Tests\Support\NewApplicationFixture;

class HttpsDownloaderTest extends TestCase
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
    public function it_downloads_through_an_allowlisted_https_redirect(): void
    {
        $transport = new InstallationTransport([
            ['status' => 302, 'body' => '', 'location' => 'https://codeload.github.com/arixphp/app/zip/sha'],
            ['status' => 200, 'body' => 'archive'],
        ]);
        new HttpsDownloader($transport)->download('https://github.com/arixphp/app/zip/sha', $this->fixture->path('download'), 100);
        $this->assertSame('archive', file_get_contents($this->fixture->path('download')));
        $this->assertSame(['https://github.com/arixphp/app/zip/sha', 'https://codeload.github.com/arixphp/app/zip/sha'], $transport->urls);
    }

    #[Test]
    #[DataProvider('unsafeUrls')]
    public function it_rejects_unsafe_redirects(string $url): void
    {
        $transport = new InstallationTransport([['status' => 302, 'body' => '', 'location' => $url]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsafe');
        new HttpsDownloader($transport)->download('https://api.github.com/repos/arixphp/app', $this->fixture->path('download'), 100);
    }

    public static function unsafeUrls(): array
    {

        return array_map(static fn (string $url): array => [$url], ['http://github.com/archive', 'https://evil.example/archive', 'https://github.com.evil.example/archive', 'https://user:pass@github.com/archive', 'https://github.com:444/archive', 'file:///etc/passwd', '//evil.example/archive', "https://github.com/\r\nX-Test: yes"]);
    }

    #[Test]
    public function it_limits_redirects(): void
    {
        $transport = new InstallationTransport(array_fill(0, 4, ['status' => 302, 'body' => '', 'location' => 'https://github.com/again']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('redirect');
        new HttpsDownloader($transport)->download('https://github.com/start', $this->fixture->path('download'), 100);
    }

    #[Test]
    #[DataProvider('badStatuses')]
    public function it_reports_http_failures(int $status): void
    {
        $transport = new InstallationTransport([['status' => $status, 'body' => 'error']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("HTTP {$status}");
        new HttpsDownloader($transport)->download('https://github.com/start', $this->fixture->path('download'), 100);
    }

    public static function badStatuses(): array
    {

        return [[404], [429], [500], [206]];
    }

    #[Test]
    public function it_checks_actual_size_even_without_content_length(): void
    {
        $transport = new InstallationTransport([['status' => 200, 'body' => '123456']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('size limit');
        new HttpsDownloader($transport)->download('https://github.com/start', $this->fixture->path('download'), 5);
    }

    #[Test]
    public function it_propagates_transport_failures(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TLS or timeout');
        new HttpsDownloader(new InstallationTransport([]))->download('https://github.com/start', $this->fixture->path('download'), 100);
    }
}
