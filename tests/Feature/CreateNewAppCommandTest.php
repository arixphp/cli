<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Installation\ApplicationInstructions;
use App\Installation\SkeletonSource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Support\FixtureSkeletonSource;
use Tests\Support\NewApplicationFixture;
use Tests\TestCase;

class CreateNewAppCommandTest extends TestCase
{
    private NewApplicationFixture $fixture;

    private string $archive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new NewApplicationFixture();
        $this->archive = $this->fixture->archive();
        $this->app->instance(SkeletonSource::class, new FixtureSkeletonSource($this->archive));
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_creates_the_application_in_the_invocation_directory_by_default(): void
    {
        $cwd = getcwd();
        chdir($this->fixture->root);

        try {
            $this->artisan('new', ['name' => 'my-app'])
                ->expectsOutputToContain($this->fixture->path('my-app'))
                ->expectsOutputToContain('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
                ->expectsOutputToContain('PHP >= 8.4.1 with ext-mbstring')
                ->assertExitCode(0);
        } finally {
            chdir($cwd);
        }

        $this->assertSame('<?php // downloaded core', file_get_contents($this->fixture->path('my-app/core/autoload.php')));
        $this->assertSame('RewriteEngine On', file_get_contents($this->fixture->path('my-app/.htaccess')));
    }

    #[Test]
    public function it_creates_the_application_inside_an_explicit_parent(): void
    {
        mkdir($this->fixture->path('projects'));
        $this->artisan('new', ['name' => 'tienda', 'directory' => $this->fixture->path('projects')])
            ->assertExitCode(0);

        $this->assertFileExists($this->fixture->path('projects/tienda/index.php'));
    }

    #[Test]
    public function it_runs_without_interaction(): void
    {
        $cwd = getcwd();
        chdir($this->fixture->root);

        try {
            $this->artisan('new', ['name' => 'plain', '--no-interaction' => true])->assertExitCode(0);
        } finally {
            chdir($cwd);
        }

        $this->assertFileExists($this->fixture->path('plain/index.php'));
    }

    #[Test]
    public function it_writes_the_cli_instructions_over_the_skeleton_file(): void
    {
        $this->artisan('new', ['name' => 'guided', 'directory' => $this->fixture->root])
            ->assertExitCode(0);

        $written = file_get_contents($this->fixture->path('guided/AGENTS.md'));

        $this->assertNotSame('Skeleton instructions.', $written);
        $this->assertSame($this->stub(), $written);
    }

    #[Test]
    public function it_does_not_publish_the_application_when_the_instructions_cannot_be_written(): void
    {
        $this->app->instance(ApplicationInstructions::class, new ApplicationInstructions($this->fixture->path('absent.md')));

        $this->artisan('new', ['name' => 'unwritten', 'directory' => $this->fixture->root])
            ->expectsOutputToContain('Cannot')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->fixture->path('unwritten'));
    }

    #[Test]
    public function it_rejects_invalid_names_with_invalid_exit_code(): void
    {
        $this->artisan('new', ['name' => '../escape', 'directory' => $this->fixture->root])
            ->assertExitCode(2);

        $this->assertFileDoesNotExist($this->fixture->path('escape'));
    }

    #[Test]
    public function it_reports_runtime_failures_without_success_output(): void
    {
        $this->app->instance(SkeletonSource::class, new FixtureSkeletonSource($this->fixture->write('broken.zip', 'broken')));
        $this->artisan('new', ['name' => 'broken', 'directory' => $this->fixture->root])
            ->expectsOutputToContain('Cannot')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->fixture->path('broken'));
    }

    #[Test]
    public function it_requires_the_name_argument(): void
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'arix', 'new', '--no-ansi'], $this->fixture->root, ['XDEBUG_MODE' => 'off']);
        $process->run();
        $this->assertSame(2, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    private function stub(): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'AGENTS.md';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
