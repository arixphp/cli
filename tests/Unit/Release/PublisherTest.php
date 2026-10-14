<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use Arix\Release\Publisher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ApplicationTestingFixture;

class PublisherTest extends TestCase
{
    private array $commands = [];

    private string $sha = '0123456789012345678901234567890123456789';

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR;
        $this->assertFileExists("{$root}Publisher.php");
        require_once "{$root}Release.php";
        require_once "{$root}Publisher.php";
    }

    private function runner(array $command): string
    {
        $this->commands[] = $command;

        if ($command[0] === 'git') {

            return "{$this->sha}\trefs/tags/v1.2.3\n";
        }

        if ($command[1] === 'api') {

            return '[[{"tag_name":"v1.2.3","draft":true}]]';
        }

        if ($command[2] === 'view') {

            return '{"tagName":"v1.2.3","isDraft":true}';
        }

        return '';
    }

    #[Test]
    public function it_reuses_existing_drafts_without_overwriting_notes(): void
    {
        $publisher = new Publisher('owner/repo', $this->runner(...));
        $publisher->draft('v1.2.3', $this->sha);
        $writes = array_filter($this->commands, static fn (array $command): bool => ($command[2] ?? '') === 'create' || ($command[2] ?? '') === 'edit');
        $this->assertSame([], $writes);
    }

    #[Test]
    public function it_does_not_treat_api_failures_as_missing_releases(): void
    {
        $publisher = new Publisher('owner/repo', function (array $command): string {
            if ($command[1] === 'api') {
                throw new RuntimeException('API unavailable');
            }

            return $this->runner($command);
        });

        $this->expectException(RuntimeException::class);

        try {
            $publisher->draft('v1.2.3', $this->sha);
        } finally {
            $this->assertCount(1, $this->commands);
        }
    }

    #[Test]
    public function it_refuses_published_releases_before_uploading_anything(): void
    {
        $publisher = new Publisher('owner/repo', function (array $command): string {
            if (($command[2] ?? '') === 'view') {

                return '{"tagName":"v1.2.3","isDraft":false}';
            }

            return $this->runner($command);
        });
        $this->expectException(RuntimeException::class);

        try {
            $publisher->draft('v1.2.3', $this->sha);
        } finally {
            $this->assertSame([], array_filter($this->commands, static fn (array $command): bool => ($command[2] ?? '') === 'upload'));
        }
    }

    #[Test]
    public function it_refuses_a_tag_that_moved_after_building(): void
    {
        $publisher = new Publisher('owner/repo', $this->runner(...));
        $this->expectException(RuntimeException::class);
        $publisher->draft('v1.2.3', str_repeat('a', 40));
    }

    #[Test]
    public function it_creates_prereleases_as_drafts_and_resolves_annotated_tags(): void
    {
        $publisher = new Publisher('owner/repo', function (array $command): string {
            $this->commands[] = $command;

            if ($command[0] === 'git') {

                return str_repeat('a', 40) . "\trefs/tags/v1.2.3-rc.1\n{$this->sha}\trefs/tags/v1.2.3-rc.1^{}\n";
            }

            if ($command[1] === 'api') {

                return '[[]]';
            }

            if ($command[2] === 'view') {

                return '{"tagName":"v1.2.3-rc.1","isDraft":true}';
            }

            return '';
        });
        $publisher->draft('v1.2.3-rc.1', $this->sha);
        $create = $this->commands[2];
        $this->assertSame(['gh', 'release', 'create', 'v1.2.3-rc.1', '--repo', 'owner/repo', '--verify-tag', '--draft', '--title', 'v1.2.3-rc.1', '--generate-notes', '--prerelease'], $create);
    }

    #[Test]
    public function it_does_not_contact_github_when_the_distribution_is_incomplete(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('dist/arix-1.2.3-linux-x64.tar.gz', 'abc');
            $publisher = new Publisher('owner/repo', $this->runner(...));
            $this->expectException(RuntimeException::class);

            try {
                $publisher->publish('v1.2.3', $this->sha, $fixture->path('dist'));
            } finally {
                $this->assertSame([], $this->commands);
            }
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_does_not_publish_after_an_upload_failure(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            foreach (['linux-x64.tar.gz', 'linux-arm64.tar.gz', 'macos-x64.tar.gz', 'macos-arm64.tar.gz', 'windows-x64.zip'] as $target) {
                $fixture->write("dist/arix-1.2.3-{$target}", 'abc');
            }

            $publisher = new Publisher('owner/repo', function (array $command): string {
                if (($command[2] ?? '') === 'upload') {
                    throw new RuntimeException('Upload failed');
                }

                return $this->runner($command);
            });
            $this->expectException(RuntimeException::class);

            try {
                $publisher->publish('v1.2.3', $this->sha, $fixture->path('dist'));
            } finally {
                $this->assertSame([], array_filter($this->commands, static fn (array $command): bool => ($command[2] ?? '') === 'edit'));
            }
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_stops_if_manual_publication_is_observed_after_the_upload(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            foreach (['linux-x64.tar.gz', 'linux-arm64.tar.gz', 'macos-x64.tar.gz', 'macos-arm64.tar.gz', 'windows-x64.zip'] as $target) {
                $fixture->write("dist/arix-1.2.3-{$target}", 'abc');
            }

            $views = 0;
            $publisher = new Publisher('owner/repo', function (array $command) use ($fixture, &$views): string {
                if (($command[2] ?? '') === 'download') {
                    $directory = $command[array_search('--dir', $command, true) + 1];

                    foreach (glob($fixture->path('dist') . DIRECTORY_SEPARATOR . '*') as $path) {
                        copy($path, $directory . DIRECTORY_SEPARATOR . basename($path));
                    }
                }

                if (($command[2] ?? '') === 'view' && ++$views === 2) {

                    return '{"tagName":"v1.2.3","isDraft":false}';
                }

                return $this->runner($command);
            });
            $this->expectException(RuntimeException::class);

            try {
                $publisher->publish('v1.2.3', $this->sha, $fixture->path('dist'));
            } finally {
                $this->assertSame([], array_filter($this->commands, static fn (array $command): bool => ($command[2] ?? '') === 'edit'));
            }
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_publishes_only_after_downloaded_assets_match_and_preserves_foreign_assets(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            foreach (['linux-x64.tar.gz', 'linux-arm64.tar.gz', 'macos-x64.tar.gz', 'macos-arm64.tar.gz', 'windows-x64.zip'] as $target) {
                $fixture->write("dist/arix-1.2.3-{$target}", 'abc');
            }

            $publisher = new Publisher('owner/repo', function (array $command) use ($fixture): string {
                if (($command[2] ?? '') === 'download') {
                    $directory = $command[array_search('--dir', $command, true) + 1];

                    foreach (glob($fixture->path('dist') . DIRECTORY_SEPARATOR . '*') as $path) {
                        copy($path, $directory . DIRECTORY_SEPARATOR . basename($path));
                    }
                }

                return $this->runner($command);
            });
            $publisher->publish('v1.2.3', $this->sha, $fixture->path('dist'));
            $last = $this->commands[array_key_last($this->commands)];
            $this->assertSame(['gh', 'release', 'edit', 'v1.2.3', '--repo', 'owner/repo', '--draft=false', '--prerelease=false', '--latest'], $last);
            $this->assertSame([], array_filter($this->commands, static fn (array $command): bool => ($command[2] ?? '') === 'delete' || ($command[2] ?? '') === 'delete-asset'));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_leaves_the_draft_unpublished_when_a_download_is_corrupt(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            foreach (['linux-x64.tar.gz', 'linux-arm64.tar.gz', 'macos-x64.tar.gz', 'macos-arm64.tar.gz', 'windows-x64.zip'] as $target) {
                $fixture->write("dist/arix-1.2.3-{$target}", 'abc');
            }

            $publisher = new Publisher('owner/repo', function (array $command) use ($fixture): string {
                if (($command[2] ?? '') === 'download') {
                    $directory = $command[array_search('--dir', $command, true) + 1];

                    foreach (glob($fixture->path('dist') . DIRECTORY_SEPARATOR . '*') as $path) {
                        copy($path, $directory . DIRECTORY_SEPARATOR . basename($path));
                    }

                    file_put_contents($directory . DIRECTORY_SEPARATOR . 'arix-1.2.3-windows-x64.zip', 'corrupt');
                }

                return $this->runner($command);
            });
            $this->expectException(RuntimeException::class);

            try {
                $publisher->publish('v1.2.3', $this->sha, $fixture->path('dist'));
            } finally {
                $this->assertSame([], array_filter($this->commands, static fn (array $command): bool => ($command[2] ?? '') === 'edit'));
            }
        } finally {
            $fixture->cleanup();
        }
    }
}
