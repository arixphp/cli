<?php

declare(strict_types=1);

namespace Tests\Feature\Make;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\NewApplicationFixture;
use Tests\TestCase;

class MiddlewareAndTestCommandTest extends TestCase
{
    #[Test]
    public function it_generates_middleware_and_unit_pest_tests(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('.arix.json', '{"format":1,"core_contract":"arix-core-v1"}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->artisan('make:middleware', ['name' => 'Audit', '--path' => $fixture->root])->assertExitCode(0);
            $this->artisan('make:test', ['name' => 'Audit', '--unit' => true, '--path' => $fixture->root])->assertExitCode(0);
            $this->assertStringContainsString('class AuditMiddleware implements Middleware', (string) file_get_contents($fixture->path('app/middleware/AuditMiddleware.php')));
            $this->assertFileExists($fixture->path('tests/Unit/AuditTest.php'));
        } finally {
            $fixture->cleanup();
        }
    }
}
