<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\BoundedDownload;
use App\Installation\CurlTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\NewApplicationFixture;

class CurlTransportTest extends TestCase
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
    public function it_bounds_real_streamed_writes(): void
    {
        $download = new BoundedDownload($this->fixture->path('download'), 5);

        try {
            $this->assertSame(3, $download->write('123'));

            try {
                $download->write('456');
                $this->fail('Oversize body accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('size limit', $exception->getMessage());
            }
        } finally {
            $download->close();
        }

        $this->assertSame('123', file_get_contents($this->fixture->path('download')));
    }

    #[Test]
    public function it_rejects_non_https_protocols_in_native_curl(): void
    {
        $file = $this->fixture->write('secret', 'do not download');
        $this->expectException(RuntimeException::class);
        new CurlTransport()->request("file://{$file}", $this->fixture->path('download'), 100);
    }

    #[Test]
    public function it_reports_real_connection_errors(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTPS request failed');
        new CurlTransport()->request('https://127.0.0.1:1/', $this->fixture->path('download'), 100);
    }

    #[Test]
    public function it_rejects_linked_download_destinations(): void
    {
        $this->fixture->write('secret', 'keep');
        symlink($this->fixture->path('secret'), $this->fixture->path('download'));

        try {
            new BoundedDownload($this->fixture->path('download'), 100);
            $this->fail('Linked output accepted.');
        } catch (RuntimeException) {
            $this->assertSame('keep', file_get_contents($this->fixture->path('secret')));
        }
    }
}
