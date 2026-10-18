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
            $fixture->write('.arix.json', '{"format":1,"core_contract":"arix-core-v1"}');
            $fixture->write('index.php', '<?php');
            $fixture->write('app/bootstrap.php', '<?php');

            $this->artisan('make:controller', ['name' => 'Admin/Post', '--path' => $fixture->root])
                ->assertExitCode(0);

            $this->assertSame("<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers\\Admin;\n\nclass PostController {}\n", file_get_contents($fixture->path('app/controllers/Admin/PostController.php')));
        } finally {
            $fixture->cleanup();
        }
    }
}
