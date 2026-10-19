<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Arix\Http\Client\Http;
use Arix\Http\Client\HttpClientException;
use Arix\Http\Client\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EchoServer;

class ClientTransportTest extends TestCase
{
    private EchoServer $server;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new EchoServer();
    }

    protected function tearDown(): void
    {
        $this->server->stop();

        parent::tearDown();
    }

    #[Test]
    public function it_sends_a_get_request_with_the_query_string(): void
    {
        $payload = $this->decode(Http::create()->get($this->server->url('/echo'), ['a' => 'b c', 'd' => 'e']));

        $this->assertSame('GET', $payload['method']);
        $this->assertSame('a=b%20c&d=e', $payload['query']);
        $this->assertStringContainsString('a=b%20c&d=e', $payload['uri']);

        $existing = $this->decode(Http::create()->get($this->server->url('/echo?existing=1'), ['a' => 'b']));

        $this->assertSame('existing=1&a=b', $existing['query']);
    }

    #[Test]
    public function it_sends_a_default_user_agent(): void
    {
        $payload = $this->decode(Http::create()->get($this->server->url('/echo')));

        $this->assertSame('Arix', $payload['headers']['user-agent']);
    }

    #[Test]
    public function it_sends_custom_headers_and_a_bearer_token(): void
    {
        $client = Http::create()->withHeaders(['X-Test' => 'yes'])->withToken('secret');

        $payload = $this->decode($client->get($this->server->url('/echo')));

        $this->assertSame('yes', $payload['headers']['x-test']);
        $this->assertSame('Bearer secret', $payload['headers']['authorization']);
    }

    #[Test]
    public function it_replaces_a_caller_supplied_content_type(): void
    {
        $client = Http::create()->withHeaders(['content-type' => 'application/vnd.api+json']);

        $payload = $this->decode($client->post($this->server->url('/echo'), ['a' => 1]));

        $this->assertSame('application/vnd.api+json', $payload['headers']['content-type']);
        $this->assertSame('{"a":1}', $payload['body']);
    }

    #[Test]
    public function it_sends_a_json_body_with_the_json_content_type(): void
    {
        $payload = $this->decode(Http::create()->post($this->server->url('/echo'), ['a' => 1]));

        $this->assertSame('{"a":1}', $payload['body']);
        $this->assertSame('application/json', $payload['headers']['content-type']);
    }

    #[Test]
    public function it_sends_a_form_body_with_the_form_content_type(): void
    {
        $payload = $this->decode(Http::create()->asForm($this->server->url('/echo'), ['a' => 'b c']));

        $this->assertSame('a=b%20c', $payload['body']);
        $this->assertSame('application/x-www-form-urlencoded', $payload['headers']['content-type']);
    }

    #[Test]
    public function it_returns_the_response_status_headers_and_body(): void
    {
        $response = Http::create()->get($this->server->url('/json'));

        $this->assertSame(200, $response->status());
        $this->assertSame('{"ok":true}', $response->body());
        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertTrue($response->successful());
    }

    #[Test]
    public function it_deserializes_a_json_response(): void
    {
        $response = Http::create()->get($this->server->url('/json'));

        $this->assertSame(['ok' => true], $response->json());
    }

    #[Test]
    public function it_decodes_a_gzipped_response(): void
    {
        $response = Http::create()->get($this->server->url('/gzip'));

        $this->assertSame('compressed', $response->body());
    }

    #[Test]
    public function it_reports_a_response_that_is_not_valid_json(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageIsOrContains('The response body is not valid JSON.');

        Http::create()->get($this->server->url('/invalid-json'))->json();
    }

    #[Test]
    public function it_does_not_follow_redirects_by_default(): void
    {
        $response = Http::create()->get($this->server->url('/redirect'));

        $this->assertSame(302, $response->status());
        $this->assertSame('/echo', $response->header('Location'));
    }

    #[Test]
    public function it_follows_redirects_when_they_are_enabled(): void
    {
        $response = Http::create()->withRedirects(3)->get($this->server->url('/redirect'));

        $this->assertSame(200, $response->status());
        $this->assertSame('GET', $this->decode($response)['method']);
        $this->assertCount(1, $response->headers()['content-type'] ?? []);
    }

    #[Test]
    public function it_sends_put_patch_and_delete_requests(): void
    {
        $client = Http::create();

        $put = $this->decode($client->put($this->server->url('/echo'), ['a' => 1]));
        $this->assertSame('PUT', $put['method']);
        $this->assertSame('{"a":1}', $put['body']);
        $this->assertSame('application/json', $put['headers']['content-type']);

        $patch = $this->decode($client->patch($this->server->url('/echo'), ['a' => 1]));
        $this->assertSame('PATCH', $patch['method']);
        $this->assertSame('{"a":1}', $patch['body']);
        $this->assertSame('application/json', $patch['headers']['content-type']);

        $delete = $this->decode($client->delete($this->server->url('/echo')));
        $this->assertSame('DELETE', $delete['method']);
        $this->assertSame('', $delete['body']);
    }

    #[Test]
    public function it_appends_the_query_before_a_url_fragment(): void
    {
        $payload = $this->decode(Http::create()->get($this->server->url('/echo?a=1#fragment'), ['b' => '2']));

        $this->assertSame('a=1&b=2', $payload['query']);

        // The fragment is a client-side detail: it is never sent to the server.
        $this->assertSame('/echo?a=1&b=2', $payload['uri']);
    }

    #[Test]
    public function it_performs_a_request_with_the_legacy_protocol_options(): void
    {
        $client = new class() extends Http
        {
            protected function supportsProtocolStrings(): bool
            {
                return false;
            }

            /** @return array<int, int|string> */
            public function restrictions(): array
            {
                return $this->protocolRestrictions();
            }
        };

        $payload = $this->decode($client->get($this->server->url('/echo')));

        $this->assertSame('GET', $payload['method']);
        $this->assertSame([
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ], $client->restrictions());
    }

    #[Test]
    public function it_times_out_on_a_slow_response(): void
    {
        try {
            Http::create()->withTimeout(1)->get($this->server->url('/slow'));
            $this->fail('A slow response must time out.');
        } catch (HttpClientException $exception) {
            $this->assertStringStartsWith('Network error: ', $exception->getMessage());
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertSame(CURLE_OPERATION_TIMEDOUT, $exception->getCode());
        }
    }

    #[Test]
    public function it_sends_a_head_request_without_a_body(): void
    {
        $response = Http::create()->head($this->server->url('/echo'));

        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body());
    }

    #[Test]
    public function it_does_not_leak_configuration_between_requests(): void
    {
        $client = Http::create();

        $client->withHeaders(['X-Test' => 'yes'])->get($this->server->url('/echo'));

        $payload = $this->decode($client->get($this->server->url('/echo')));

        $this->assertArrayNotHasKey('x-test', $payload['headers']);
    }

    #[Test]
    public function it_rejects_a_response_that_exceeds_the_maximum_size(): void
    {
        try {
            Http::create()->withMaxBytes(1024)->get($this->server->url('/large?bytes=4096'));
            $this->fail('An oversized response must be rejected.');
        } catch (HttpClientException $exception) {
            $this->assertSame('The response exceeded the maximum size of 1024 bytes.', $exception->getMessage());

            // CURLE_WRITE_ERROR proves the transfer was aborted by the write callback while
            // streaming, instead of downloading the whole body and rejecting it afterwards.
            $this->assertSame(CURLE_WRITE_ERROR, $exception->getCode());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $payload = json_decode($response->body(), true);

        $this->assertIsArray($payload);

        return $payload;
    }
}
