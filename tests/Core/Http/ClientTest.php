<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Arix\Constants\HttpMethod;
use Arix\Http\Client\Http;
use Arix\Http\Client\HttpClientException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\StandaloneCore;

class ClientTest extends TestCase
{
    #[DataProvider('unsupportedUrls')]
    #[Test]
    public function it_rejects_urls_that_are_not_http_or_https(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Only HTTP and HTTPS URLs are supported.');

        Http::create()->get($url);
    }

    public static function unsupportedUrls(): array
    {
        return [
            ['ftp://example.com/file'],
            ['file:///etc/passwd'],
            ['example.com/path'],
            ['not a url'],
        ];
    }

    #[Test]
    public function it_rejects_non_positive_timeouts(): void
    {
        $client = Http::create();

        foreach ([['withTimeout', 0, 'Timeout must be positive.'], ['withTimeout', -1, 'Timeout must be positive.'], ['withConnectTimeout', 0, 'Connect timeout must be positive.']] as [$method, $seconds, $message]) {
            try {
                $client->{$method}($seconds);
                $this->fail("{$method}({$seconds}) must be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_rejects_redirect_limits_outside_the_allowed_range(): void
    {
        $client = Http::create();

        foreach ([-1, 11] as $maximum) {
            try {
                $client->withRedirects($maximum);
                $this->fail("withRedirects({$maximum}) must be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Redirect limit must be between 0 and 10.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_rejects_a_maximum_response_size_that_is_not_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The maximum response size must be positive.');

        Http::create()->withMaxBytes(0);
    }

    #[Test]
    public function it_rejects_json_and_form_payloads_together(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Choose JSON or form data, not both.');

        Http::create()->request(HttpMethod::GET, 'https://example.com', json: [], form: []);
    }

    #[Test]
    public function it_reports_a_payload_that_cannot_be_encoded_as_json(): void
    {
        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageIsOrContains('The request payload could not be encoded as JSON.');

        Http::create()->post('https://example.com', ['invalid' => "\xB1\x31"]);
    }

    #[Test]
    public function it_returns_a_new_instance_for_every_configuration(): void
    {
        $client = Http::create();

        $this->assertNotSame($client, $client->withHeaders(['X-Test' => 'yes']));
        $this->assertNotSame($client, $client->withToken('secret'));
        $this->assertNotSame($client, $client->withTimeout(10));
        $this->assertNotSame($client, $client->withConnectTimeout(10));
        $this->assertNotSame($client, $client->withMaxBytes(1024));
        $this->assertNotSame($client, $client->withRedirects(3));
    }

    #[Test]
    public function it_reports_a_missing_curl_extension(): void
    {
        $client = new class() extends Http
        {
            protected function curlAvailable(): bool
            {
                return false;
            }
        };

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessageIsOrContains('The Arix HTTP client requires ext-curl.');

        $client->get('http://127.0.0.1:' . $this->closedPort() . '/');
    }

    #[Test]
    public function it_reports_a_connection_failure(): void
    {
        try {
            Http::create()->withAllowedHosts(['127.0.0.1'])->get('http://127.0.0.1:' . $this->closedPort() . '/');
            $this->fail('A refused connection must be reported.');
        } catch (HttpClientException $exception) {
            $this->assertStringStartsWith('Network error: ', $exception->getMessage());
            $this->assertSame(CURLE_COULDNT_CONNECT, $exception->getCode());
        }
    }

    #[Test]
    #[DataProvider('reservedHosts')]
    public function it_refuses_to_reach_a_reserved_address(string $url, string $expected): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($expected);
        Http::create()->get($url);
    }

    public static function reservedHosts(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/', 'reserved address'],
            'loopback name' => ['http://localhost/', 'reserved address'],
            'private' => ['http://192.168.1.1/', 'reserved address'],
            'private class a' => ['http://10.0.0.1/', 'reserved address'],
            'link local' => ['http://169.254.169.254/latest/meta-data/', 'reserved address'],
            'unspecified' => ['http://0.0.0.0/', 'reserved address'],
            'multicast' => ['http://224.0.0.1/', 'reserved address'],
            'ipv6 loopback' => ['http://[::1]/', 'reserved address'],
        ];
    }

    #[Test]
    public function it_refuses_a_host_outside_the_allowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('is not allowed');
        Http::create()->withAllowedHosts(['api.example.test'])->get('http://other.example.test/');
    }

    #[Test]
    public function it_rejects_header_values_that_contain_line_breaks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Header names and values must not contain line breaks.');

        Http::create()->withHeaders(['X-Test' => "yes\r\nX-Injected: pwned"]);
    }

    #[Test]
    public function it_rejects_header_names_that_contain_line_breaks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Header names and values must not contain line breaks.');

        Http::create()->withHeaders(["X-Test\r\nX-Injected" => 'yes']);
    }

    #[Test]
    public function it_restricts_the_protocols_to_http_and_https(): void
    {
        $client = new class() extends Http
        {
            /** @return array<int, int|string> */
            public function restrictions(): array
            {
                return $this->protocolRestrictions();
            }
        };

        $expected = defined('CURLOPT_PROTOCOLS_STR')
            ? [CURLOPT_PROTOCOLS_STR => 'http,https', CURLOPT_REDIR_PROTOCOLS_STR => 'http,https']
            : [CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS];

        $this->assertSame($expected, $client->restrictions());
    }

    #[Test]
    public function it_falls_back_to_the_protocol_bitmask_on_older_libcurl(): void
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

        $this->assertSame([
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ], $client->restrictions());
    }

    #[Test]
    public function it_resolves_the_client_without_composer(): void
    {
        $root = dirname(__DIR__, 3);
        $program = <<<'PHP'
            require $argv[1];
            echo json_encode([
                'composer' => class_exists('Composer\Autoload\ClassLoader', false),
                'client' => class_exists('Arix\Http\Client\Http'),
                'response' => class_exists('Arix\Http\Client\Response'),
                'exception' => class_exists('Arix\Http\Client\HttpClientException'),
            ], JSON_THROW_ON_ERROR);
            PHP;
        $process = new Process(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, StandaloneCore::autoload()],
            $root,
            ['XDEBUG_MODE' => 'off'],
        );
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());

        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertFalse($result['composer']);
        $this->assertTrue($result['client']);
        $this->assertTrue($result['response']);
        $this->assertTrue($result['exception']);
    }

    private function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);

        fclose($socket);

        $position = strrpos($name, ':');

        return (int) substr($name, $position + 1);
    }
}
