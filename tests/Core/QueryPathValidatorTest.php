<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Http\HttpException;
use Arix\Http\QueryPathValidator;
use Arix\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class QueryPathValidatorTest extends TestCase
{
    #[DataProvider('invalidPaths')]
    #[Test]
    public function it_invalid_or_ambiguous_paths_are_rejected(string $uri): void
    {
        $request = new Request(server: ['REQUEST_URI' => $uri]);

        try {
            (new QueryPathValidator)->path($request);
            $this->fail('Invalid path must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->status);
        }
    }

    public static function invalidPaths(): array
    {
        return array_map(static fn (string $uri): array => [$uri], [
            '/bad%xx', '/bad%2fpath', '/bad%5Cpath', '/%00', '/%FF', '/..', '/%2e%2e/x', '/.env', '/a//b', '/a\\b',
        ]);
    }

    #[Test]
    public function it_unicode_is_decoded_once_and_base_path_is_segment_bounded(): void
    {
        $validator = new QueryPathValidator('/site');
        $request = new Request(server: ['REQUEST_URI' => '/site/caf%C3%A9?x=1']);
        $this->assertSame('/café', $validator->path($request));
        $this->assertSame('/', $validator->path(new Request(server: ['REQUEST_URI' => '/site'])));

        try {
            $validator->path(new Request(server: ['REQUEST_URI' => '/site-other/page']));
            $this->fail('Different base prefix must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->status);
        }
    }
}
