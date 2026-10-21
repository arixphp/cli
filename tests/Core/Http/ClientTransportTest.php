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

    private function client(): Http
    {
        return Http::create()->withAllowedHosts([$this->server->host()]);
    }

    #[Test]
    public function it_sends_a_get_request_with_the_query_string(): void
    {
        $payload = $this->decode($this->client()->get($this->server->url('/echo'), ['a' => 'b c', 'd' => 'e']));

        $this->assertSame('GET', $payload['method']);
        $this->assertSame('a=b%20c&d=e', $payload['query']);
        $this->assertStringContainsString('a=b%20c&d=e', $payload['uri']);

        $existing = $this->decode($this->client()->get($this->server->url('/echo?existing=1'), ['a' => 'b']));

        $this->assertSame('existing=1&a=b', $existing['query']);
    }

    #[Test]
    public function it_sends_a_default_user_agent(): void
    {
        $payload = $this->decode($this->client()->get($this->server->url('/echo')));

        $this->assertSame('Arix', $payload['headers']['user-agent']);
    }

    #[Test]
    public function it_sends_custom_headers_and_a_bearer_token(): void
    {
        $client = $this->client()->withHeaders(['X-Test' => 'yes'])->withToken('secret');

        $payload = $this->decode($client->get($this->server->url('/echo')));

        $this->assertSame('yes', $payload['headers']['x-test']);
        $this->assertSame('Bearer secret', $payload['headers']['authorization']);
    }

    #[Test]
    public function it_replaces_a_caller_supplied_content_type(): void
    {
        $client = $this->client()->withHeaders(['content-type' => 'application/vnd.api+json']);

        $payload = $this->decode($client->post($this->server->url('/echo'), ['a' => 1]));

        $this->assertSame('application/vnd.api+json', $payload['headers']['content-type']);
        $this->assertSame('{"a":1}', $payload['body']);
    }

    #[Test]
    public function it_sends_a_json_body_with_the_json_content_type(): void
    {
        $payload = $this->decode($this->client()->post($this->server->url('/echo'), ['a' => 1]));

        $this->assertSame('{"a":1}', $payload['body']);
        $this->assertSame('application/json', $payload['headers']['content-type']);
    }

    #[Test]
    public function it_sends_a_form_body_with_the_form_content_type(): void
    {
        $payload = $this->decode($this->client()->asForm($this->server->url('/echo'), ['a' => 'b c']));

        $this->assertSame('a=b%20c', $payload['body']);
        $this->assertSame('application/x-www-form-urlencoded', $payload['headers']['content-type']);
    }

    #[Test]
    public function it_returns_the_response_status_headers_and_body(): void
    {
        $response = $this->client()->get($this->server->url('/json'));

        $this->assertSame(200, $response->status());
        $this->assertSame('{"ok":true}', $response->body());
        $this->assertSame('application/json', $response->header('Content-Type'));
        $this->assertTrue($response->successful());
    }

    #[Test]
    public function it_deserializes_a_json_response(): void
    {
        $response = $this->client()->get($this->server->url('/json'));

        $this->assertSame(['ok' => true], $response->json());
    }

    #[Test]
    public function it_decodes_a_gzipped_response(): void
    {
        $response = $this->client()->get($this->server->url('/gzip'));

        $this->assertSame('compressed', $response->body());
    }

    #[Test]
    public function it_reports_a_response_that_is_not_valid_json(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageIsOrContains('The response body is not valid JSON.');

        $this->client()->get($this->server->url('/invalid-json'))->json();
    }

    #[Test]
    public function it_does_not_follow_redirects_by_default(): void
    {
        $response = $this->client()->get($this->server->url('/redirect'));

        $this->assertSame(302, $response->status());
        $this->assertSame('/echo', $response->header('Location'));
    }

    #[Test]
    public function it_follows_redirects_when_they_are_enabled(): void
    {
        $response = $this->client()->withRedirects(3)->get($this->server->url('/redirect'));

        $this->assertSame(200, $response->status());
        $this->assertSame('GET', $this->decode($response)['method']);
        $this->assertCount(1, $response->headers()['content-type'] ?? []);
    }

    #[Test]
    public function it_does_not_send_credentials_to_another_host(): void
    {
        $client = Http::create()
            ->withAllowedHosts([$this->server->host(), 'localhost'])
            ->withRedirects(3)
            ->withHeaders(['X-Secret' => 'value', 'Cookie' => 'session=secret'])
            ->withToken('secret');
        $payload = $this->decode($client->get($this->server->url('/redirect-host')));

        $this->assertArrayNotHasKey('x-secret', $payload['headers']);
        $this->assertArrayNotHasKey('authorization', $payload['headers']);
        $this->assertArrayNotHasKey('cookie', $payload['headers']);
    }

    #[Test]
    public function it_preserves_credentials_for_a_same_origin_redirect(): void
    {
        $client = $this->client()->withRedirects(1)->withToken('secret')
            ->withHeaders(['Cookie' => 'session=secret', 'X-Secret' => 'value']);
        $payload = $this->decode($client->get($this->server->url('/redirect')));

        $this->assertSame('Bearer secret', $payload['headers']['authorization']);
        $this->assertSame('session=secret', $payload['headers']['cookie']);
        $this->assertSame('value', $payload['headers']['x-secret']);
    }

    #[Test]
    public function it_removes_credentials_at_another_port_and_does_not_restore_them_in_the_chain(): void
    {
        $other = new EchoServer();
        $client = $this->client()->withRedirects(2)->withToken('secret')
            ->withHeaders(['Cookie' => 'session=secret', 'X-Secret' => 'value']);

        try {
            $target = $other->url('/echo');
            $payload = $this->decode($client->get($this->server->url('/redirect-target'), ['url' => $target]));
            $this->assertArrayNotHasKey('authorization', $payload['headers']);
            $this->assertArrayNotHasKey('cookie', $payload['headers']);
            $this->assertArrayNotHasKey('x-secret', $payload['headers']);

            $return = $other->url('/redirect-target?url=' . rawurlencode($this->server->url('/echo')));
            $returned = $this->decode($client->get($this->server->url('/redirect-target'), ['url' => $return]));
            $this->assertArrayNotHasKey('authorization', $returned['headers']);
            $this->assertArrayNotHasKey('cookie', $returned['headers']);
            $this->assertArrayNotHasKey('x-secret', $returned['headers']);

            $direct = $this->decode($client->get($this->server->url('/echo')));
            $this->assertSame('Bearer secret', $direct['headers']['authorization']);
            $this->assertSame('session=secret', $direct['headers']['cookie']);
            $this->assertSame('value', $direct['headers']['x-secret']);
        } finally {
            $other->stop();
        }
    }

    #[Test]
    public function it_sends_put_patch_and_delete_requests(): void
    {
        $client = $this->client();

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
        $payload = $this->decode($this->client()->get($this->server->url('/echo?a=1#fragment'), ['b' => '2']));

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

        $payload = $this->decode($client->withAllowedHosts([$this->server->host()])->get($this->server->url('/echo')));

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
            $this->client()->withTimeout(1)->get($this->server->url('/slow'));
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
        $response = $this->client()->head($this->server->url('/echo'));

        $this->assertSame(200, $response->status());
        $this->assertSame('', $response->body());
    }

    #[Test]
    public function it_does_not_leak_configuration_between_requests(): void
    {
        $client = $this->client();

        $client->withHeaders(['X-Test' => 'yes'])->get($this->server->url('/echo'));

        $payload = $this->decode($client->get($this->server->url('/echo')));

        $this->assertArrayNotHasKey('x-test', $payload['headers']);
    }

    #[Test]
    public function it_rejects_a_response_that_exceeds_the_maximum_size(): void
    {
        try {
            $this->client()->withMaxBytes(1024)->get($this->server->url('/large?bytes=4096'));
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
