<?php

declare(strict_types=1);

namespace App\Installation;

interface SkeletonSource
{
    public function download(string $workspace): string;
}
