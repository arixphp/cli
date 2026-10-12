<?php

declare(strict_types=1);

namespace Tests\Unit\Css\Update;

use App\Css\Update\CssTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CssUpdateFixture;

class CssTargetTest extends TestCase
{
    private CssUpdateFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CssUpdateFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_resolves_an_application_directory(): void
    {
        $application = $this->fixture->application();

        $this->assertSame($application, CssTarget::resolve($application, $this->fixture->root));
    }

    #[Test]
    public function it_rejects_a_missing_directory(): void
    {
        $this->expectException(RuntimeException::class);
        CssTarget::resolve($this->fixture->path('missing'), $this->fixture->root);
    }

    #[Test]
    public function it_rejects_a_target_overlapping_the_distribution_source(): void
    {
        $application = $this->fixture->application();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('overlap');
        CssTarget::resolve($application, $this->fixture->root, $this->fixture->root);
    }

    #[Test]
    public function it_rejects_an_application_without_a_styles_directory(): void
    {
        $application = $this->fixture->application('application', null);
        unlink($this->fixture->path('application/styles/app.css'));
        rmdir($this->fixture->path('application/styles'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('styles');
        CssTarget::resolve($application, $this->fixture->root);
    }

    #[Test]
    public function it_rejects_an_application_without_entry_files(): void
    {
        $application = $this->fixture->application();
        unlink($this->fixture->path('application/index.php'));

        $this->expectException(RuntimeException::class);
        CssTarget::resolve($application, $this->fixture->root);
    }
}
