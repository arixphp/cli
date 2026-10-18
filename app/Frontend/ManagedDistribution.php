<?php

declare(strict_types=1);

namespace App\Frontend;

interface ManagedDistribution
{
    public function directory(): string;

    public function version(): string;

    /** @return array<string, string> */
    public function inventory(): array;

    public function contents(string $file): string;

    /** @param array<string, string> $inventory */
    public function releaseOf(array $inventory): string|null;
}
