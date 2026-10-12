<?php

declare(strict_types=1);

namespace Tests\Unit\Js\Update;

use App\Js\Update\JsTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\JsUpdateFixture;

class JsTargetTest extends TestCase
{
    private JsUpdateFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new JsUpdateFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_resolves_an_application_directory(): void
    {
        $application = $this->fixture->application();

        $this->assertSame($application, JsTarget::resolve($application, $this->fixture->root));
    }

    #[Test]
    public function it_rejects_a_missing_directory(): void
    {
        $this->expectException(RuntimeException::class);
        JsTarget::resolve($this->fixture->path('missing'), $this->fixture->root);
    }

    #[Test]
    public function it_rejects_a_target_overlapping_the_distribution_source(): void
    {
        $application = $this->fixture->application();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('overlap');
        JsTarget::resolve($application, $this->fixture->root, $this->fixture->root);
    }

    #[Test]
    public function it_rejects_an_application_without_a_scripts_directory(): void
    {
        $application = $this->fixture->application('application', null);
        unlink($this->fixture->path('application/scripts/app.js'));
        rmdir($this->fixture->path('application/scripts'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('scripts');
        JsTarget::resolve($application, $this->fixture->root);
    }

    #[Test]
    public function it_rejects_an_application_without_entry_files(): void
    {
        $application = $this->fixture->application();
        unlink($this->fixture->path('application/index.php'));

        $this->expectException(RuntimeException::class);
        JsTarget::resolve($application, $this->fixture->root);
    }
}
