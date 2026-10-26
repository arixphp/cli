<?php

declare(strict_types=1);

namespace Tests\Feature\Make;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\NewApplicationFixture;
use Tests\TestCase;

class ControllerCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_nested_controller_in_an_explicit_application(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->artisan('make:controller', ['name' => 'Admin/Post', '--path' => $fixture->root])
                ->assertExitCode(0);

            $this->assertContains('Controllers', scandir($fixture->path('app')));
            $this->assertSame("<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers\\Admin;\n\nclass PostController {}\n", file_get_contents($fixture->path('app/Controllers/Admin/PostController.php')));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_preserves_existing_controllers_unless_forced(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');
            $fixture->write('app/Controllers/PostController.php', 'sentinel');

            $this->artisan('make:controller', ['name' => 'Post', '--path' => $fixture->root])
                ->expectsOutputToContain('Already exists app/Controllers/PostController.php.')
                ->assertExitCode(0);
            $this->assertSame('sentinel', file_get_contents($fixture->path('app/Controllers/PostController.php')));

            $this->artisan('make:controller', ['name' => 'Post', '--path' => $fixture->root, '--force' => true])
                ->assertExitCode(0);
            $this->assertStringContainsString('class PostController {}', (string) file_get_contents($fixture->path('app/Controllers/PostController.php')));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_accepts_one_controller_suffix_and_rejects_extensions(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->artisan('make:controller', ['name' => 'Admin/PostController', '--path' => $fixture->root])->assertExitCode(0);
            $this->assertFileExists($fixture->path('app/Controllers/Admin/PostController.php'));

            $this->artisan('make:controller', ['name' => 'Post.php', '--path' => $fixture->root])->assertExitCode(2);
            $this->assertFileDoesNotExist($fixture->path('app/Controllers/Post.phpController.php'));
        } finally {
            $fixture->cleanup();
        }
    }
}
