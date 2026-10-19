<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Arix\Http\Client\HttpClientException;
use Arix\Http\Client\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    #[Test]
    public function it_reports_the_status_headers_and_body(): void
    {
        $response = new Response(200, ['content-type' => ['application/json']], '{"ok":true}');

        $this->assertSame(200, $response->status());
        $this->assertSame('{"ok":true}', $response->body());
        $this->assertSame(['content-type' => ['application/json']], $response->headers());
        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertNull($response->header('X-Missing'));
    }

    #[Test]
    public function it_deserializes_a_json_body(): void
    {
        $response = new Response(200, [], '{"ok":true}');

        $this->assertSame(['ok' => true], $response->json());
    }

    #[Test]
    public function it_reports_a_response_that_is_not_valid_json(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageIsOrContains('The response body is not valid JSON.');

        (new Response(200, [], 'not json'))->json();
    }

    #[Test]
    public function it_classifies_successful_client_and_server_errors(): void
    {
        $successful = new Response(200, [], '');
        $clientError = new Response(404, [], '');
        $serverError = new Response(500, [], '');

        $this->assertTrue($successful->successful());
        $this->assertFalse($successful->failed());
        $this->assertFalse($successful->clientError());
        $this->assertFalse($successful->serverError());

        $this->assertFalse($clientError->successful());
        $this->assertTrue($clientError->failed());
        $this->assertTrue($clientError->clientError());
        $this->assertFalse($clientError->serverError());

        $this->assertFalse($serverError->successful());
        $this->assertTrue($serverError->failed());
        $this->assertFalse($serverError->clientError());
        $this->assertTrue($serverError->serverError());
    }

    #[Test]
    public function it_throws_for_a_failed_status(): void
    {
        try {
            (new Response(404, [], ''))->throw();
            $this->fail('A failed status must be reported.');
        } catch (HttpClientException $exception) {
            $this->assertSame('HTTP request failed with status 404', $exception->getMessage());
            $this->assertSame(404, $exception->getCode());
        }
    }

    #[Test]
    public function it_returns_itself_for_a_successful_status(): void
    {
        $response = new Response(200, [], '');

        $this->assertSame($response, $response->throw());
    }
}
