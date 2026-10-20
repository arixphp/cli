<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
use App\Core\Update\GitHubCoreDistribution;
use App\Installation\HttpsDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CoreUpdateFixture;
use Tests\Support\InstallationTransport;
use ZipArchive;

class GitHubCoreDistributionTest extends TestCase
{
    private CoreUpdateFixture $fixture;

    private const string SHA = '1234567890abcdef1234567890abcdef12345678';

    protected function setUp(): void
    {
        $this->fixture = new CoreUpdateFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_loads_only_the_bundle_from_a_commit_pinned_stable_release(): void
    {
        $bundle = $this->bundle();
        $archive = $this->archive($bundle);
        $transport = $this->transport($archive);
        $remote = new GitHubCoreDistribution(new HttpsDownloader($transport), $this->fixture->root)->latest();

        $this->assertSame('1.1.0', $remote->version);
        $this->assertSame(self::SHA, $remote->commit);
        $this->assertSame('<?php // candidate', $remote->distribution->contents('New.php'));
        $this->assertSame([
            'https://api.github.com/repos/arixphp/cli/releases/latest',
            'https://api.github.com/repos/arixphp/cli/commits/v1.1.0',
            'https://codeload.github.com/arixphp/cli/zip/' . self::SHA,
        ], $transport->urls);
        $this->assertSame([], glob($this->fixture->path('arix-core-download-*')));
        $this->assertFileDoesNotExist($this->fixture->path('executed'));
    }

    #[Test]
    #[DataProvider('invalidReleases')]
    public function it_rejects_invalid_release_metadata(string $metadata): void
    {
        $transport = new InstallationTransport([['status' => 200, 'body' => $metadata]]);

        try {
            new GitHubCoreDistribution(new HttpsDownloader($transport), $this->fixture->root)->latest();
            $this->fail('Invalid release accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('release', $exception->getMessage());
            $this->assertCount(1, $transport->urls);
            $this->assertSame([], glob($this->fixture->path('arix-core-download-*')));
        }
    }

    public static function invalidReleases(): array
    {

        return array_map(static fn (string $metadata): array => [$metadata], [
            'not json', 'null', '[]', '{}',
            '{"draft":true,"prerelease":false,"tag_name":"1.1.0"}',
            '{"draft":false,"prerelease":true,"tag_name":"1.1.0"}',
            '{"draft":false,"prerelease":false,"tag_name":"1.1.0-rc.1"}',
            '{"draft":false,"prerelease":false,"tag_name":"../main"}',
            '{"draft":false,"prerelease":false,"tag_name":"01.1.0"}',
            '{"draft":false,"prerelease":false,"tag_name":[]}',
        ]);
    }

    #[Test]
    public function it_refuses_to_download_an_unpinned_commit(): void
    {
        $transport = new InstallationTransport([
            ['status' => 200, 'body' => '{"draft":false,"prerelease":false,"tag_name":"1.1.0"}'],
            ['status' => 200, 'body' => '{"sha":"main"}'],
        ]);

        try {
            new GitHubCoreDistribution(new HttpsDownloader($transport), $this->fixture->root)->latest();
            $this->fail('Unpinned commit accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('commit', $exception->getMessage());
            $this->assertCount(2, $transport->urls);
            $this->assertSame([], glob($this->fixture->path('arix-core-download-*')));
        }
    }

    #[Test]
    #[DataProvider('invalidArchives')]
    public function it_rejects_invalid_bundles_and_cleans_temporary_downloads(string $kind): void
    {
        $bundle = $this->bundle();
        $archive = match ($kind) {
            'zip' => 'not a zip',
            'missing' => $this->archive($bundle, 'cli-wrong/resources/core.bundle'),
            'manifest' => $this->archive('{"manifest":{"format":2},"contents":{}}'),
            'corrupt' => $this->archive(str_replace(base64_encode('<?php // candidate'), base64_encode('<?php // corrupted'), $bundle)),
            'oversized' => $this->archive(str_repeat('x', 33554433)),
            'symlink' => $this->archive($bundle, link: true),
        };

        try {
            new GitHubCoreDistribution(new HttpsDownloader($this->transport($archive)), $this->fixture->root)->latest();
            $this->fail('Invalid remote bundle accepted.');
        } catch (RuntimeException) {
            $this->assertSame([], glob($this->fixture->path('arix-core-download-*')));
        }
    }

    public static function invalidArchives(): array
    {

        return [['zip'], ['missing'], ['manifest'], ['corrupt'], ['oversized'], ['symlink']];
    }

    #[Test]
    public function it_cleans_downloads_after_network_failure(): void
    {
        try {
            new GitHubCoreDistribution(new HttpsDownloader(new InstallationTransport([])), $this->fixture->root)->latest();
            $this->fail('Unavailable upstream accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('timeout', $exception->getMessage());
            $this->assertSame([], glob($this->fixture->path('arix-core-download-*')));
        }
    }

    private function bundle(): string
    {
        CoreDistribution::build($this->fixture->core('source', ['New.php' => '<?php // candidate']), $this->fixture->path('core.bundle'));

        return file_get_contents($this->fixture->path('core.bundle'));
    }

    private function archive(string $bundle, string|null $entry = null, bool $link = false): string
    {
        $entry ??= 'cli-' . self::SHA . '/resources/core.bundle';
        $path = $this->fixture->path('release.zip');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($entry, $bundle);

        if ($link) {
            $zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }

        $zip->addFromString('cli-' . self::SHA . '/arix', '<?php file_put_contents("executed", "bad");');
        $zip->addFromString('../outside.php', '<?php // never extracted');
        $zip->close();

        return file_get_contents($path);
    }

    private function transport(string $archive): InstallationTransport
    {

        return new InstallationTransport([
            ['status' => 200, 'body' => '{"draft":false,"prerelease":false,"tag_name":"v1.1.0"}'],
            ['status' => 200, 'body' => '{"sha":"' . self::SHA . '"}'],
            ['status' => 200, 'body' => $archive],
        ]);
    }
}
