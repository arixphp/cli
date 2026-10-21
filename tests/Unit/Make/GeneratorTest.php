<?php

declare(strict_types=1);

namespace Tests\Unit\Make;

use App\Core\Update\ApplicationTarget;
use App\Make\GenerationPolicy;
use App\Make\Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $temporary = realpath(sys_get_temp_dir());

        $this->assertNotFalse($temporary);

        $this->root = $temporary . DIRECTORY_SEPARATOR . 'arix-make-' . bin2hex(random_bytes(8));

        mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0700, true);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.arix.json', '{"format":1,"core_contract":"arix-core-v1"}');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'index.php', '<?php');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php', '<?php');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    #[Test]
    public function it_generates_a_nested_confined_php_file(): void
    {
        $root = ApplicationTarget::resolve($this->root, $this->root);
        $policy = new GenerationPolicy('app/Controllers', 'App\\Controllers', 'Controller', '.php', "<?php\nnamespace {{ namespace }};\nclass {{ class }} {}\n", GenerationPolicy::CLASS_NAME);

        $result = (new Generator())->generate($root, $policy, 'Admin/Post', false);

        $this->assertSame('app/Controllers/Admin/PostController.php', $result->path);
        $this->assertTrue($result->written);
        $this->assertSame("<?php\nnamespace App\\Controllers\\Admin;\nclass PostController {}\n", file_get_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR . 'PostController.php'));
    }

    #[Test]
    public function it_rejects_unsafe_names_without_writing(): void
    {
        $root = ApplicationTarget::resolve($this->root, $this->root);
        $policy = new GenerationPolicy('app/Controllers', 'App\\Controllers', 'Controller', '.php', '', GenerationPolicy::CLASS_NAME);

        foreach (['../Escape', '/tmp/Escape', 'Admin//Post', 'Post.php'] as $name) {
            try {
                (new Generator())->generate($root, $policy, $name, false);
                $this->fail("Expected {$name} to be rejected.");
            } catch (RuntimeException|InvalidArgumentException) {
                $this->assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'EscapeController.php');
            }
        }
    }

    #[Test]
    public function it_rejects_linked_output_segments(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are unavailable.');
        }

        symlink(sys_get_temp_dir(), $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers');
        $root = ApplicationTarget::resolve($this->root, $this->root);
        $policy = new GenerationPolicy('app/Controllers', 'App\\Controllers', 'Controller', '.php', '', GenerationPolicy::CLASS_NAME);

        $this->expectException(RuntimeException::class);
        (new Generator())->generate($root, $policy, 'Post', false);
    }

    #[Test]
    public function it_rejects_an_existing_directory_with_different_case_without_writing(): void
    {
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers');
        $root = ApplicationTarget::resolve($this->root, $this->root);
        $policy = new GenerationPolicy('app/Controllers', 'App\\Controllers', 'Controller', '.php', '<?php', GenerationPolicy::CLASS_NAME);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('case');

        try {
            (new Generator())->generate($root, $policy, 'Post', false);
        } finally {
            $this->assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'PostController.php');
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }
        }

        rmdir($path);
    }
}
