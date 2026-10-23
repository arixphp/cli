<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Http\RequestUrlContext;
use Arix\HttpFoundation\Request;
use Arix\Routing\ApplicationUrl;
use Arix\Routing\UrlGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ApplicationUrlTest extends TestCase
{
    #[Test]
    #[DataProvider('validUrls')]
    public function it_generates_absolute_urls_and_a_coherent_console_request(array $config, string $root, string $prefix): void
    {
        $url = ApplicationUrl::fromConfiguration($config);
        $this->assertSame($root, $url->url);
        $this->assertSame($prefix, $url->basePath);
        $request = $url->request();
        $this->assertSame('/', $request->getPathInfo());
        $this->assertSame($prefix, rawurldecode($request->getBaseUrl()));
        $this->assertSame("{$root}/about", UrlGenerator::fromApplicationUrl($root)->to('/about'));
        $this->assertSame("{$root}/about", UrlGenerator::fromRequest($request, RequestUrlContext::fromRequest($request))->to('/about'));
    }

    public static function validUrls(): array
    {

        return [
            [[], 'http://localhost', ''],
            [['url' => 'https://myapp.com/subdirectory/'], 'https://myapp.com/subdirectory', '/subdirectory'],
            [['url' => 'http://localhost:8181/'], 'http://localhost:8181', ''],
            [['url' => 'https://[::1]:8443/sub'], 'https://[::1]:8443/sub', '/sub'],
            [['url' => 'https://myapp.com/caf%C3%A9'], 'https://myapp.com/caf%C3%A9', '/café'],
        ];
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_rejects_invalid_configuration_without_echoing_its_value(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid application URL.');
        ApplicationUrl::fromConfiguration(['url' => $value]);
    }

    public static function invalidUrls(): array
    {

        return array_map(static fn (mixed $value): array => [$value], [
            null, false, 1, [], '', '/sub', 'ftp://myapp.com/sub', 'https://user:secret@myapp.com',
            'https://myapp.com/?a=secret', 'https://myapp.com/#secret', 'https://myapp.com:99999',
            'https://myapp.com/%252e', 'https://myapp.com/a%2fb', 'https://myapp.com/a%5cb',
            'https://myapp.com/.env', 'https://myapp.com/a//b', 'https://myapp.com/%00',
            'https://myapp.com/%FF', 'https://myapp.com/a\\b',
        ]);
    }

    #[Test]
    public function it_requires_an_absolute_root_for_url_generation(): void
    {
        $root = ApplicationUrl::fromString('https://myapp.com/sub');
        $urls = new UrlGenerator($root, '/about');
        $this->assertSame('https://myapp.com/sub/about', $urls->to('/about'));
        $this->assertSame('/about', $urls->currentPath());
        $this->assertSame('https://myapp.com/sub/about', $urls->current());
    }

    #[Test]
    public function it_constructs_a_unicode_mount_from_decoded_parts_without_decoding_again(): void
    {
        $root = ApplicationUrl::fromParts('https://myapp.com', '/café');
        $this->assertSame('https://myapp.com/caf%C3%A9', $root->url);
        $this->assertSame('https://myapp.com/caf%C3%A9/ni%C3%B1o', new UrlGenerator($root)->to('/niño'));
    }

    #[Test]
    #[DataProvider('invalidParts')]
    public function it_rejects_invalid_origins_and_mounts(string $origin, string $mount): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApplicationUrl::fromParts($origin, $mount);
    }

    public static function invalidParts(): array
    {

        return [
            ['', ''], ['/relative', ''], ['https://myapp.com/path', ''],
            ['https://user:secret@myapp.com', ''], ['https://myapp.com?secret=1', ''],
            ['https://myapp.com', '/%252e'], ['https://myapp.com', '/caf%C3%A9'],
            ['https://myapp.com', '/.env'], ['https://myapp.com', '/sub/'],
            ['https://myapp.com', '//evil.test'], ['https://myapp.com', '/sub:port'],
        ];
    }

    #[Test]
    public function it_uses_the_request_not_untrusted_forwarded_headers(): void
    {
        $request = new Request(server: [
            'REQUEST_URI' => '/sub/about?secret=1', 'SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/index.php',
            'HTTP_HOST' => 'myapp.com', 'HTTPS' => 'on', 'SERVER_PORT' => 443, 'REMOTE_ADDR' => '192.0.2.1',
            'HTTP_X_FORWARDED_HOST' => 'evil.test', 'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_PREFIX' => '/evil',
        ]);
        $urls = UrlGenerator::fromRequest($request, RequestUrlContext::fromRequest($request));
        $this->assertSame('https://myapp.com/sub/about', $urls->to('/about'));
        $this->assertSame('https://myapp.com/sub/about', $urls->current());
        $this->assertSame('/about', $urls->currentPath());
    }

    #[Test]
    public function it_keeps_a_trusted_external_prefix_out_of_internal_routing(): void
    {
        $proxies = Request::getTrustedProxies();
        $headers = Request::getTrustedHeaderSet();

        try {
            Request::setTrustedProxies(['192.0.2.1'], Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO);
            $request = new Request(server: [
                'REQUEST_URI' => '/sub/about', 'SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/index.php',
                'HTTP_HOST' => 'backend.test', 'REMOTE_ADDR' => '192.0.2.1', 'HTTP_X_FORWARDED_HOST' => 'myapp.com',
                'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PREFIX' => '/external',
            ]);
            $urls = UrlGenerator::fromRequest($request, RequestUrlContext::fromRequest($request));
            $this->assertSame('/about', $urls->currentPath());
            $this->assertSame('https://myapp.com/external/sub/about', $urls->to('/about'));
        } finally {
            Request::setTrustedProxies($proxies, $headers);
        }
    }
}
