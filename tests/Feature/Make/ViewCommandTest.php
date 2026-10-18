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
            $fixture->write('.arix.json', '{"format":1,"core_contract":"arix-core-v1"}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->artisan('make:view', ['name' => 'Blog.Post', '--path' => $fixture->root])->assertExitCode(0);
            $this->assertSame('', file_get_contents($fixture->path('app/views/blog/post.arix.php')));
        } finally {
            $fixture->cleanup();
        }
    }
}
