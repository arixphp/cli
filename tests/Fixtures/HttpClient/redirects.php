<?php

declare(strict_types=1);

use Arix\Constants\HttpMethod;
use Arix\Http\Client\Http;
use Arix\Http\Client\HttpClientException;
use Arix\Http\Client\Response;

require $argv[1];

$scenario = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
$client = new class($scenario['responses']) extends Http
{
    public array $sent = [];

    public function __construct(private array $responses)
    {
        parent::__construct();
    }

    protected function curlAvailable(): bool
    {

        return true;
    }

    protected function send(HttpMethod $method, string $url, array $headers, string|null $body): Response
    {
        $this->sent[] = ['method' => $method->value, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $response = array_shift($this->responses);
        $location = $response['location'] ?? null;

        return new Response($response['status'] ?? 200, $location === null ? [] : ['location' => [$location]], 'fixture');
    }
};
$client = $client->withRedirects($scenario['redirects'] ?? 3)->withToken('secret')
    ->withHeaders(['Cookie' => 'session=secret', 'X-Secret' => 'value']);
$error = null;

try {
    $client->post($scenario['url'], ['payload' => 'fixture']);
} catch (HttpClientException $exception) {
    $error = $exception->getMessage();
}

echo json_encode(['error' => $error, 'sent' => $client->sent], JSON_THROW_ON_ERROR);
