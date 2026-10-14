<?php

declare(strict_types=1);

namespace Arix\Release;

use Closure;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function is_array;

class Publisher
{
    /** @param Closure(list<string>): string $run */
    public function __construct(private readonly string $repository, private readonly Closure $run)
    {
        if (preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~', $repository) !== 1) {
            throw new RuntimeException('Invalid GitHub repository.');
        }
    }

    public function remoteTag(string $tag, string $sha): void
    {
        Release::version($tag);

        if (preg_match('/\A[0-9a-f]{40}\z/', $sha) !== 1) {
            throw new RuntimeException('Invalid release commit.');
        }

        $output = ($this->run)(['git', 'ls-remote', '--exit-code', 'origin', "refs/tags/{$tag}", "refs/tags/{$tag}^{}"]);
        $refs = [];

        foreach (explode("\n", trim($output)) as $line) {
            $parts = preg_split('/\s+/', $line, 2);

            if ($parts !== false && isset($parts[1])) {
                $refs[$parts[1]] = $parts[0];
            }
        }

        $remote = $refs["refs/tags/{$tag}^{}"] ?? $refs["refs/tags/{$tag}"] ?? null;

        if ($remote !== $sha) {
            throw new RuntimeException('Release tag changed or does not exist on origin.');
        }
    }

    public function draft(string $tag, string $sha): void
    {
        $this->remoteTag($tag, $sha);
        $pages = json_decode(($this->run)(['gh', 'api', '--paginate', '--slurp', "repos/{$this->repository}/releases?per_page=100"]), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($pages)) {
            throw new RuntimeException('Invalid release listing.');
        }

        foreach ($pages as $page) {
            foreach ($page as $release) {
                if (($release['tag_name'] ?? null) === $tag) {
                    $this->guard($tag);

                    return;
                }
            }
        }

        $arguments = ['gh', 'release', 'create', $tag, '--repo', $this->repository, '--verify-tag', '--draft', '--title', Release::title($tag), '--generate-notes'];

        if (Release::prerelease($tag)) {
            $arguments[] = '--prerelease';
        }

        ($this->run)($arguments);
        $this->guard($tag);
    }

    private function guard(string $tag): void
    {
        $release = json_decode(($this->run)(['gh', 'release', 'view', $tag, '--repo', $this->repository, '--json', 'tagName,isDraft']), true, flags: JSON_THROW_ON_ERROR);

        if (($release['tagName'] ?? null) !== $tag || ($release['isDraft'] ?? null) !== true) {
            throw new RuntimeException('Only the matching draft release may be changed.');
        }
    }

    public function publish(string $tag, string $sha, string $directory): void
    {
        Release::checksums($tag, $directory);
        $this->remoteTag($tag, $sha);
        $this->guard($tag);
        $names = [...Release::archives($tag), 'SHA256SUMS'];
        $files = array_map(static fn (string $name): string => $directory . DIRECTORY_SEPARATOR . $name, $names);
        ($this->run)(['gh', 'release', 'upload', $tag, '--repo', $this->repository, '--clobber', ...$files]);
        $temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-release-' . bin2hex(random_bytes(16));

        if (! mkdir($temporary, 0700)) {
            throw new RuntimeException('Cannot prepare asset verification directory.');
        }

        try {
            $arguments = ['gh', 'release', 'download', $tag, '--repo', $this->repository, '--dir', $temporary];

            foreach ($names as $name) {
                $arguments[] = '--pattern';
                $arguments[] = $name;
            }

            ($this->run)($arguments);
            Release::verify($tag, $directory, $temporary);
            $this->remoteTag($tag, $sha);
            $this->guard($tag);
            $prerelease = Release::prerelease($tag) ? 'true' : 'false';
            ($this->run)(['gh', 'release', 'edit', $tag, '--repo', $this->repository, '--draft=false', "--prerelease={$prerelease}", Release::prerelease($tag) ? '--latest=false' : '--latest']);
        } finally {
            (new Filesystem())->remove($temporary);
        }
    }
}
