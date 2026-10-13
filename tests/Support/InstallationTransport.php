<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Installation\HttpResponse;
use App\Installation\HttpTransport;
use RuntimeException;

class InstallationTransport implements HttpTransport
{
    /** @var list<string> */
    public array $urls = [];

    /** @param list<array{status: int, body: string, location?: string}> $responses */
    public function __construct(private array $responses) {}

    public function request(string $url, string $destination, int $maxBytes): HttpResponse
    {
        $this->urls[] = $url;
        $response = array_shift($this->responses);

        if ($response === null) {
            throw new RuntimeException('Simulated TLS or timeout failure.');
        }

        file_put_contents($destination, $response['body']);

        return new HttpResponse($response['status'], $response['location'] ?? null);
    }
}
