<?php

declare(strict_types=1);

namespace Tests\Feature\Make;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\NewApplicationFixture;
use Tests\TestCase;

class ViewCommandTest extends TestCase
{
    #[Test]
    public function it_generates_an_empty_laravel_style_view(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->artisan('make:view', ['name' => 'Blog.Post', '--path' => $fixture->root])->assertExitCode(0);
            $this->assertSame('', file_get_contents($fixture->path('app/views/blog/post.arix.php')));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_normalizes_all_supported_view_notations_and_rejects_extensions(): void
    {
        foreach (['blog.post', 'blog/post', 'Blog\\Post'] as $name) {
            $fixture = new NewApplicationFixture();

            try {
                $fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
                $fixture->write('index.php', '<?php');
                $fixture->write('app/bootstrap.php', '<?php');

                $this->artisan('make:view', ['name' => $name, '--path' => $fixture->root])->assertExitCode(0);
                $this->assertSame('', file_get_contents($fixture->path('app/views/blog/post.arix.php')));
            } finally {
                $fixture->cleanup();
            }
        }

        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->artisan('make:view', ['name' => 'blog.post.arix.php', '--path' => $fixture->root])->assertExitCode(2);
            $this->assertFileDoesNotExist($fixture->path('app/views/blog/post.arix.php'));
        } finally {
            $fixture->cleanup();
        }
    }
}
