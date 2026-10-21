<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Support\ApplicationAutoloader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ApplicationAutoloaderTest extends TestCase
{
    private string $root;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-autoload-' . bin2hex(random_bytes(8));
        $this->id = 'Probe' . bin2hex(random_bytes(6));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app', 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
        parent::tearDown();
    }

    #[Test]
    public function it_loads_nested_classes_interfaces_traits_and_enums_on_demand(): void
    {
        $namespace = "App\\Domain\\{$this->id}";
        $this->write("Domain/{$this->id}/Contract.php", "namespace {$namespace}; interface Contract {}");
        $this->write("Domain/{$this->id}/Label.php", "namespace {$namespace}; trait Label { public function label(): string { return 'loaded'; } }");
        $this->write("Domain/{$this->id}/State.php", "namespace {$namespace}; enum State { case Ready; }");
        $this->write("Domain/{$this->id}/Item.php", "namespace {$namespace}; class Item implements Contract { use Label; public function state(): State { return State::Ready; } }");
        $this->write("Domain/{$this->id}/Unused.php", "throw new \\RuntimeException('Must not scan classes.');");

        ApplicationAutoloader::run($this->root, function () use ($namespace): void {
            $class = "{$namespace}\\Item";
            $item = new $class();
            $this->assertSame('loaded', $item->label());
            $this->assertSame('Ready', $item->state()->name);
            $this->assertInstanceOf("{$namespace}\\Contract", $item);
        });
    }

    #[Test]
    public function it_loads_controllers_and_middleware_with_exact_namespace_directory_names(): void
    {
        foreach (['Controllers', 'Middleware'] as $namespace) {
            $class = "App\\{$namespace}\\{$this->id}";
            $this->write("{$namespace}/{$this->id}.php", "namespace App\\{$namespace}; class {$this->id} {}");

            ApplicationAutoloader::run($this->root, function () use ($class): void {
                $this->assertTrue(class_exists($class));
            });
        }
    }

    #[Test]
    public function it_does_not_execute_resources_entry_files_or_wrong_case_paths(): void
    {
        $paths = [
            "views/{$this->id}", "config/{$this->id}", "lang/{$this->id}",
            'bootstrap', 'routes', 'services', 'schedules',
            "utils/{$this->id}", "Utils/{$this->id}wrong", "controllers/{$this->id}", "middleware/{$this->id}",
        ];

        foreach ($paths as $path) {
            $this->write("{$path}.php", "throw new \\RuntimeException('Unexpected autoload include.');");
        }

        ApplicationAutoloader::run($this->root, function (): void {
            foreach (["views\\{$this->id}", "config\\{$this->id}", "lang\\{$this->id}", 'bootstrap', 'routes', 'services', 'schedules', "Utils\\{$this->id}", "Utils\\{$this->id}Wrong", "Controllers\\{$this->id}", "Middleware\\{$this->id}", 'Middleware\\..\\bootstrap'] as $relative) {
                $this->assertFalse(class_exists("App\\{$relative}"));
            }
        });
    }

    #[Test]
    public function it_does_not_follow_file_or_directory_symlinks(): void
    {
        $this->write('outside.php', "throw new \\RuntimeException('Linked file loaded.');");
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Utils');
        symlink($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'outside.php', $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Utils' . DIRECTORY_SEPARATOR . "{$this->id}.php");
        symlink($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Utils', $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Linked');

        ApplicationAutoloader::run($this->root, function (): void {
            $this->assertFalse(class_exists("App\\Utils\\{$this->id}"));
            $this->assertFalse(class_exists("App\\Linked\\{$this->id}"));
        });
    }

    #[Test]
    public function it_restores_nested_roots_after_errors_without_accumulating_callbacks(): void
    {
        $second = $this->root . DIRECTORY_SEPARATOR . 'second';
        mkdir($second . DIRECTORY_SEPARATOR . 'app', 0755, true);
        $this->write("Utils/{$this->id}.php", "namespace App\\Utils; class {$this->id} {}");
        $before = spl_autoload_functions();

        ApplicationAutoloader::run($this->root, function () use ($second): void {
            try {
                ApplicationAutoloader::run($second, function (): void {
                    $this->assertFalse(class_exists("App\\Utils\\{$this->id}"));

                    throw new RuntimeException('restore');
                });
            } catch (RuntimeException $exception) {
                $this->assertSame('restore', $exception->getMessage());
            }

            $this->assertTrue(class_exists("App\\Utils\\{$this->id}"));
        });

        $this->assertSame($before, spl_autoload_functions());
    }

    #[Test]
    public function it_restores_the_standalone_root_after_a_temporary_scope_without_composer(): void
    {
        $core = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core';
        $filesystem = new Filesystem();
        $filesystem->mirror($core, $this->root . DIRECTORY_SEPARATOR . 'core');
        $this->write("Services/{$this->id}.php", "namespace App\\Services; class {$this->id} { public static function message(): string { return 'standalone'; } }");
        $other = $this->root . DIRECTORY_SEPARATOR . 'other';
        mkdir($other . DIRECTORY_SEPARATOR . 'app', 0755, true);
        $program = <<<'PHP'
require $argv[1] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
$class = $argv[3];
$before = count(spl_autoload_functions());
\Arix\Support\ApplicationAutoloader::run($argv[2], static function () use ($class): void {
    if (class_exists($class)) { throw new RuntimeException('Leaked outer application root.'); }
});
if (! class_exists($class)) { throw new RuntimeException('Outer root not restored.'); }
\Arix\Support\ApplicationAutoloader::register($argv[1]);
if (count(spl_autoload_functions()) !== $before) { throw new RuntimeException('Duplicate callback.'); }
if (class_exists('Composer\Autoload\ClassLoader', false)) { throw new RuntimeException('Composer loaded.'); }
echo $class::message();
PHP;
        $process = new Process([PHP_BINARY, '-r', $program, $this->root, $other, "App\\Services\\{$this->id}"], $other);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('standalone', $process->getOutput());
    }

    #[Test]
    public function it_rejects_a_linked_application_directory_without_loading_its_files(): void
    {
        $this->write("Utils/{$this->id}.php", "throw new \\RuntimeException('Linked application loaded.');");
        rename($this->root . DIRECTORY_SEPARATOR . 'app', $this->root . DIRECTORY_SEPARATOR . 'target');
        symlink($this->root . DIRECTORY_SEPARATOR . 'target', $this->root . DIRECTORY_SEPARATOR . 'app');

        ApplicationAutoloader::run($this->root, function (): void {
            $this->assertFalse(class_exists("App\\Utils\\{$this->id}"));
        });
    }

    #[Test]
    public function it_pins_linked_roots_without_changing_the_outer_scope(): void
    {
        $link = $this->root . DIRECTORY_SEPARATOR . 'linked';
        symlink($this->root, $link);
        $this->write("Utils/{$this->id}.php", "namespace App\\Utils; class {$this->id} {}");
        $before = spl_autoload_functions();

        ApplicationAutoloader::run($link, function () use ($link): void {
            unlink($link);
            symlink($this->root . DIRECTORY_SEPARATOR . 'app', $link);
            $this->assertTrue(class_exists("App\\Utils\\{$this->id}"));
        });

        $this->assertSame($before, spl_autoload_functions());
    }

    private function write(string $relative, string $body): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, "<?php\ndeclare(strict_types=1);\n{$body}\n");
    }
}
