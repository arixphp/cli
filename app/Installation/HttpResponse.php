<?php

declare(strict_types=1);

namespace App\Installation;

class HttpResponse
{
    public function __construct(public readonly int $status, public readonly string|null $location = null) {}
}
