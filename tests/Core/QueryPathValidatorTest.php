<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Constants\HttpStatusCode;
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
            (new QueryPathValidator())->path($request);
            $this->fail('Invalid path must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(HttpStatusCode::BAD_REQUEST, $exception->status);
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
            $this->assertSame(HttpStatusCode::NOT_FOUND, $exception->status);
        }
    }

    #[Test]
    public function it_rejects_a_path_that_still_carries_an_escape(): void
    {
        $validator = new QueryPathValidator();
        $request = new Request(server: ['REQUEST_URI' => '/a%252e%252e/b']);

        try {
            $validator->path($request);
            $this->fail('A doubly encoded path must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(HttpStatusCode::BAD_REQUEST, $exception->status);
        }
    }

    #[Test]
    public function it_rejects_an_excessive_path(): void
    {
        $validator = new QueryPathValidator();
        $request = new Request(server: ['REQUEST_URI' => '/' . str_repeat('a', QueryPathValidator::MAX_PATH_BYTES)]);

        try {
            $validator->path($request);
            $this->fail('An excessive path must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(HttpStatusCode::BAD_REQUEST, $exception->status);
        }
    }
}
