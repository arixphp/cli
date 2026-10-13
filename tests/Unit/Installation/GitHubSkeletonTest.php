<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\GitHubSkeleton;
use App\Installation\HttpsDownloader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InstallationTransport;
use Tests\Support\NewApplicationFixture;

class GitHubSkeletonTest extends TestCase
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
    public function it_downloads_the_resolved_commit_not_a_moving_branch(): void
    {
        $sha = str_repeat('a', 40);
        $transport = new InstallationTransport([
            ['status' => 200, 'body' => json_encode(['sha' => $sha], JSON_THROW_ON_ERROR)],
            ['status' => 200, 'body' => 'zip'],
        ]);
        $commit = new GitHubSkeleton(new HttpsDownloader($transport))->download($this->fixture->root);
        $this->assertSame($sha, $commit);
        $this->assertSame('zip', file_get_contents($this->fixture->path('skeleton.zip')));
        $this->assertSame(['https://api.github.com/repos/arixphp/app/commits/main', "https://codeload.github.com/arixphp/app/zip/{$sha}"], $transport->urls);
    }

    #[Test]
    #[DataProvider('invalidResponses')]
    public function it_rejects_malformed_commit_responses(string $body): void
    {
        $transport = new InstallationTransport([['status' => 200, 'body' => $body]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('commit');
        new GitHubSkeleton(new HttpsDownloader($transport))->download($this->fixture->root);
    }

    public static function invalidResponses(): array
    {

        return [['{'], ['[]'], ['{"sha":"main"}'], ['{"sha":"../../escape"}'], ['{"sha":40}'], ['{"sha":[] }']];
    }
}
