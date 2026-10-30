<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FixturePathsTest extends TestCase
{
    #[Test]
    public function it_keeps_resource_fixtures_in_a_lowercase_directory(): void
    {
        $tests = dirname(__DIR__, 2);
        $entries = scandir($tests);
        $this->assertContains('fixtures', $entries);
        $this->assertNotContains('Fixtures', $entries);
        $this->assertFileExists($tests . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'TemplateCacheCleanup' . DIRECTORY_SEPARATOR . 'worker.php');
    }
}
