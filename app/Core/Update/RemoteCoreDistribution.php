<?php

declare(strict_types=1);

namespace App\Core\Update;

class RemoteCoreDistribution
{
    public function __construct(
        public readonly CoreDistribution $distribution,
        public readonly string $version,
        public readonly string $commit,
    ) {}
}
