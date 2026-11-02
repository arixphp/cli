<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Constants\HttpStatusCode;
use Arix\HttpFoundation\Response;
use Arix\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TypeError;

class TestingResponseTest extends TestCase
{
    #[Test]
    public function it_asserts_status_with_an_enum(): void
    {
        $response = new TestResponse(new Response('ok', 200));
        $this->assertSame($response, $response->assertStatus(HttpStatusCode::OK));
    }

    #[Test]
    public function it_fails_when_the_status_does_not_match(): void
    {
        $response = new TestResponse(new Response('missing', 404));
        $this->expectException(AssertionFailedError::class);
        $response->assertStatus(HttpStatusCode::OK);
    }

    #[Test]
    public function it_rejects_integer_statuses(): void
    {
        $response = new TestResponse(new Response('ok', 200));
        $this->expectException(TypeError::class);
        $response->assertStatus(200);
    }
}
