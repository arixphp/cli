<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Http\HttpException;
use Arix\Http\RequestUrlContext;
use Arix\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequestUrlContextTest extends TestCase
{
    #[Test]
    #[DataProvider('mounts')]
    public function it_detects_and_validates_the_mount(string $uri, array $server, array $expected): void
    {
        $request = new Request(server: ['REQUEST_URI' => $uri, 'SCRIPT_FILENAME' => '/srv/site/index.php', ...$server]);
        $context = RequestUrlContext::fromRequest($request);
        $this->assertSame($expected, [$context->baseUrl, $context->basePath, $context->path]);

        foreach ($server as $key => $value) {
            $this->assertSame($value, $request->server->get($key));
        }
    }

    public static function mounts(): array
    {

        return [
            ['/sub/about?x=1', ['SCRIPT_NAME' => '/sub/index.php'], ['/sub', '/sub', '/about']],
            ['/sub', ['SCRIPT_NAME' => '/sub/index.php'], ['/sub', '/sub', '/']],
            ['/sub/', ['SCRIPT_NAME' => '/sub/index.php'], ['/sub', '/sub', '/']],
            ['/about', ['SCRIPT_NAME' => '/index.php'], ['', '', '/about']],
            ['/sub/about', [], ['', '', '/sub/about']],
            ['/sub/about', ['PHP_SELF' => '/sub/index.php'], ['/sub', '/sub', '/about']],
            ['/sub/about', ['ORIG_SCRIPT_NAME' => '/sub/index.php'], ['/sub', '/sub', '/about']],
            ['/sub/index.php/about', ['SCRIPT_NAME' => '/sub/index.php'], ['/sub/index.php', '/sub', '/about']],
            ['/caf%C3%A9/ni%C3%B1o', ['SCRIPT_NAME' => '/café/index.php'], ['/café', '/café', '/niño']],
            ['/caf%C3%A9/about', ['SCRIPT_NAME' => '/caf%C3%A9/index.php'], ['/café', '/café', '/about']],
            ['/caf%C3%A9/index.php/about', ['SCRIPT_NAME' => '/caf%C3%A9/index.php'], ['/café/index.php', '/café', '/about']],
            ['/caf%C3%A9/about', ['PHP_SELF' => '/caf%C3%A9/index.php'], ['/café', '/café', '/about']],
            ['/caf%C3%A9/about', ['ORIG_SCRIPT_NAME' => '/caf%C3%A9/index.php'], ['/café', '/café', '/about']],
        ];
    }

    #[Test]
    #[DataProvider('invalidPaths')]
    public function it_rejects_invalid_bytes_even_inside_the_mount(string $uri): void
    {
        $request = new Request(server: ['REQUEST_URI' => $uri, 'SCRIPT_FILENAME' => '/srv/index.php', 'SCRIPT_NAME' => dirname($uri) . '/index.php']);
        $this->expectException(HttpException::class);
        RequestUrlContext::fromRequest($request);
    }

    public static function invalidPaths(): array
    {

        return array_map(static fn (string $path): array => [$path], [
            '/bad%2fmount/about', '/bad%5cmount/about', '/%252e/about', '/%00/about', '/%FF/about',
            '/.. /about', '/.env/about', '/a//b/about', '/a\\b/about', '/' . str_repeat('a', 4096) . '/about',
        ]);
    }

    #[Test]
    public function it_does_not_strip_a_partial_mount_segment(): void
    {
        $request = new Request(server: ['REQUEST_URI' => '/submarine/about', 'SCRIPT_FILENAME' => '/srv/index.php', 'SCRIPT_NAME' => '/sub/index.php']);
        $this->assertSame('/submarine/about', RequestUrlContext::fromRequest($request)->path);
    }
}
