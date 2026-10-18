<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Css\Update\CssDistribution;

class CssManagedDistribution implements ManagedDistribution
{
    public function __construct(private readonly CssDistribution $distribution) {}

    public function directory(): string
    {

        return 'styles/arix';
    }

    public function version(): string
    {

        return $this->distribution->version;
    }

    public function inventory(): array
    {

        return $this->distribution->hashes;
    }

    public function contents(string $file): string
    {

        return $this->distribution->contents($file);
    }

    public function releaseOf(array $inventory): string|null
    {

        return $this->distribution->releaseOf($inventory);
    }
}
