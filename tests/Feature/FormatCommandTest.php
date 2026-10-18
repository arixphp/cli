<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ApplicationTestingFixture;
use Tests\TestCase;

class FormatCommandTest extends TestCase
{
    private ApplicationTestingFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new ApplicationTestingFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_formats_app_and_tests_directories(): void
    {
        $this->fixture->write('app/ExampleController.php', '<?php\nclass ExampleController{\npublic function index(){return 1;}\n}\n');
        $this->fixture->write('tests/ExampleTest.php', '<?php\ntest("example", function () { expect(1)->toBe(1); });');

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_excludes_app_views_directory(): void
    {
        $this->fixture->write('app/views/Layout.arix.php', '<?php // view');
        $this->fixture->write('app/Controller.php', '<?php\nclass Controller{}\n');

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_uses_user_config_file(): void
    {
        $this->fixture->write('.arix-format.json', '{"rules":{"no_unused_imports":true}}');
        $this->fixture->write('app/Controller.php', '<?php\nclass Controller{}\n');

        $this->artisan('format', [
            '--path' => $this->fixture->root,
            '--config' => $this->fixture->path('.arix-format.json'),
        ])->assertExitCode(0);
    }

    #[Test]
    public function it_rejects_config_with_directories(): void
    {
        $this->fixture->write('.arix-format.json', '{"paths":{"include":["src"]}}');

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->expectsOutputToContain('Unsupported key')
            ->assertExitCode(2);
    }

    #[Test]
    public function it_rejects_missing_application(): void
    {
        $this->artisan('format', ['--path' => '/nonexistent/path'])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(2);
    }
}
