<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Installation\SkeletonSource;
use Closure;
use RuntimeException;

class FixtureSkeletonSource implements SkeletonSource
{
    public function __construct(private readonly string $archive, private readonly Closure|null $duringDownload = null) {}

    public function download(string $workspace): string
    {
        if ($this->duringDownload !== null) {
            ($this->duringDownload)($workspace);
        }

        if (! copy($this->archive, $workspace . DIRECTORY_SEPARATOR . 'skeleton.zip')) {
            throw new RuntimeException('Fixture copy failed.');
        }

        return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    }
}
