<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Support\Path;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PathTest extends TestCase
{
    #[Test]
    #[DataProvider('filesystemPaths')]
    public function it_distinguishes_rooted_filesystem_paths_from_relative_paths(string $path, bool $absolute): void
    {
        $this->assertSame($absolute, Path::isAbsolute($path));
    }

    public static function filesystemPaths(): array
    {

        return [
            'POSIX root' => ['/', true],
            'POSIX directory' => ['/var/cache/arix', true],
            'Windows drive with backslashes' => ['C:\\Users\\runner\\cache', true],
            'Windows drive with forward slashes' => ['D:/a/cli/cache', true],
            'Windows lowercase drive' => ['c:\\cache', true],
            'Windows drive root' => ['C:\\', true],
            'Windows UNC share' => ['\\\\server\\share\\cache', true],
            'Windows rooted directory' => ['\\cache', true],
            'Windows forward-slash UNC share' => ['//server/share/cache', true],
            'empty' => ['', false],
            'relative directory' => ['storage/cache', false],
            'relative Windows directory' => ['storage\\cache', false],
            'explicit relative directory' => ['./cache', false],
            'parent-relative directory' => ['../cache', false],
            'Windows drive-relative directory' => ['C:cache', false],
            'Windows drive-relative root' => ['C:', false],
            'invalid drive letter' => ['1:/cache', false],
            'URI is not a filesystem root' => ['https://example.test/cache', false],
        ];
    }
}
