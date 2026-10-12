<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Constants\HttpStatusCode;
use Arix\Http\ErrorResponder;
use Arix\Http\HttpException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypeError;

class HttpExceptionTest extends TestCase
{
    #[Test]
    public function it_preserves_http_error_enum_cases(): void
    {
        foreach ([HttpStatusCode::BAD_REQUEST, HttpStatusCode::CONTENT_TOO_LARGE, HttpStatusCode::METHOD_NOT_ALLOWED, HttpStatusCode::INTERNAL_SERVER_ERROR, HttpStatusCode::SERVICE_UNAVAILABLE, HttpStatusCode::NETWORK_AUTHENTICATION_REQUIRED] as $status) {
            $this->assertSame($status, new HttpException($status, 'Error')->status);
        }
    }

    #[Test]
    #[DataProvider('nonErrorStatuses')]
    public function it_rejects_enum_cases_that_are_not_http_errors(HttpStatusCode $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HttpException($status, 'Error');
    }

    public static function nonErrorStatuses(): array
    {
        return [[HttpStatusCode::CONTINUE], [HttpStatusCode::OK], [HttpStatusCode::REDIRECT_FOUND], [HttpStatusCode::REDIRECT_PERMANENT]];
    }

    #[Test]
    #[DataProvider('integerStatuses')]
    public function it_requires_an_enum_instead_of_an_integer(int $status): void
    {
        $this->expectException(TypeError::class);
        new HttpException($status, 'Error');
    }

    public static function integerStatuses(): array
    {
        return [[400], [503], [599]];
    }

    #[Test]
    public function it_preserves_the_enum_status_and_headers_in_http_responses(): void
    {
        $exception = new HttpException(HttpStatusCode::SERVICE_UNAVAILABLE, 'Unavailable.', ['Retry-After' => '60']);
        $response = new ErrorResponder()->respond($exception);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<pre>Unavailable.</pre>', $response->getContent());
        $this->assertSame('60', $response->headers->get('Retry-After'));
    }
}
