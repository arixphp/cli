<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Arix\Http\RequestAttributes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequestAttributesTest extends TestCase
{
    #[Test]
    public function it_defines_the_existing_internal_attribute_names(): void
    {
        $this->assertSame('_arix_path', RequestAttributes::PATH);
        $this->assertSame('_arix_url_context', RequestAttributes::URL_CONTEXT);
        $this->assertSame('_arix_route_parameters', RequestAttributes::ROUTE_PARAMETERS);
    }
}
