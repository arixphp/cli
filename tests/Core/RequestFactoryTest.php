<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Http\HttpException;
use Arix\Http\RequestFactory;
use Arix\HttpFoundation\Request;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequestFactoryTest extends TestCase
{
    #[DataProvider('methods')]
    #[Test]
    public function it_capture_preserves_http_method_headers_query_and_repeatable_body(string $method): void
    {
        $request = (new RequestFactory(4))->create([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => '/hook?q=ok',
            'QUERY_STRING' => 'q=ok',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HOOK' => 'secret',
        ], query: ['q' => 'ok'], content: 'data');
        $this->assertSame($method, $request->getMethod());
        $this->assertSame('secret', $request->headers->get('X-Hook'));
        $this->assertSame('application/json', $request->headers->get('Content-Type'));
        $this->assertSame('ok', $request->query->get('q'));
        $this->assertSame('data', $request->getContent());
        $this->assertSame('data', $request->getContent());
    }

    public static function methods(): array
    {
        return array_map(static fn (string $method): array => [$method], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'QUERY', 'HEAD', 'OPTIONS', 'CUSTOM']);
    }

    #[Test]
    public function it_stream_is_read_only_up_to_limit_plus_one_even_with_false_content_length(): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, '123456789');
        rewind($stream);

        try {
            (new RequestFactory(4))->create(['CONTENT_LENGTH' => '1'], content: $stream);
            $this->fail('Oversized body must fail.');
        } catch (HttpException $exception) {
            $this->assertSame(413, $exception->status);
            $this->assertSame(5, ftell($stream));
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function it_injected_strings_obey_the_same_limit(): void
    {
        $this->expectException(HttpException::class);
        (new RequestFactory(4))->create([], content: '12345');
    }

    #[DataProvider('invalidLimits')]
    #[Test]
    public function it_limit_must_be_a_positive_bounded_integer(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestFactory($limit);
    }

    public static function invalidLimits(): array
    {
        return [[0], [-1], [PHP_INT_MAX]];
    }

    #[Test]
    public function it_capture_does_not_enable_method_override_or_trust_forwarded_headers(): void
    {
        $request = (new RequestFactory)->create([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.1',
        ], post: ['_method' => 'PATCH']);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('127.0.0.1', $request->getClientIp());
    }

    #[Test]
    public function it_global_capture_uses_php_parsed_post_values(): void
    {
        $original = [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES];

        try {
            $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/hook'];
            $_GET = [];
            $_POST = ['name' => 'Arix'];
            $_COOKIE = [];
            $_FILES = [];
            $request = (new RequestFactory)->fromGlobals();
            $this->assertSame('Arix', $request->request->get('name'));
            $this->assertSame('', $request->getContent());
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE, $_FILES] = $original;
        }
    }

    #[Test]
    public function it_capture_preserves_modified_headers_attributes_and_request_format(): void
    {
        $request = Request::create('/hook', 'POST', content: 'body');
        $request->headers->set('Authorization', 'Bearer example');
        $request->headers->remove('User-Agent');
        $request->attributes->set('custom', 'value');
        $request->setRequestFormat('json');
        (new RequestFactory)->capture($request);
        $this->assertSame('Bearer example', $request->headers->get('Authorization'));
        $this->assertFalse($request->headers->has('User-Agent'));
        $this->assertSame('value', $request->attributes->get('custom'));
        $this->assertSame('json', $request->getRequestFormat());
        $this->assertSame('body', $request->getContent());
    }
}
