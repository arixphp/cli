<?php

declare(strict_types=1);

namespace App\Installation;

interface HttpTransport
{
    public function request(string $url, string $destination, int $maxBytes): HttpResponse;
}
