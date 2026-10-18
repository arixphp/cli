<?php

declare(strict_types=1);

namespace App\Make;

class GenerationResult
{
    public function __construct(public readonly string $path, public readonly bool $written) {}
}
