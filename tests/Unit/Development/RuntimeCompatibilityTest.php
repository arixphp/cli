<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\RuntimeProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RuntimeCompatibilityTest extends TestCase
{
    #[Test]
    public function it_supports_the_application_php_runtime_and_mercure(): void
    {
        $runtime = getenv('ARIX_DEV_TEST_RUNTIME');

        if ($runtime === false || $runtime === '') {
            $this->markTestSkipped('Set ARIX_DEV_TEST_RUNTIME to an explicitly trusted native FrankenPHP executable.');
        }

        new RuntimeProbe()->verify($runtime);

        $this->addToAssertionCount(1);
    }
}
