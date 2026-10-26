<?php

declare(strict_types=1);

namespace Tests\Core\Autoload;

use Arix\Autoload\AutoloadConfiguration;
use Arix\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

use function count;

class ClassLoaderTest extends TestCase
{
    private string $root;

    private string $prefix;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-loader-' . bin2hex(random_bytes(8));
        $this->prefix = 'Probe' . bin2hex(random_bytes(6)) . '\\';
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        ClassLoader::current()?->unregister();
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function it_loads_pure_psr4_with_specific_prefixes_and_shorter_prefix_fallbacks(): void
    {
        $this->write('base/Domain/Item.php', '<?php throw new \\RuntimeException("Wrong prefix");');
        $this->write('specific/Item.php', '<?php namespace ' . $this->prefix . 'Domain; class Item {}');
        $this->write('base/Domain/Other.php', '<?php namespace ' . $this->prefix . 'Domain; interface Other {}');
        $this->write('base/views/Template.php', '<?php namespace ' . $this->prefix . 'views; trait Template {}');
        $this->write('base/routes.php', '<?php namespace ' . rtrim($this->prefix, '\\') . '; enum routes { case Ready; }');
        $loader = $this->loader([$this->prefix => 'base/', $this->prefix . 'Domain\\' => 'specific/']);
        $before = spl_autoload_functions();
        $loader->register();
        $loader->register();

        try {
            $this->assertTrue(class_exists($this->prefix . 'Domain\\Item'));
            $this->assertTrue(interface_exists($this->prefix . 'Domain\\Other'));
            $this->assertTrue(trait_exists($this->prefix . 'views\\Template'));
            $this->assertTrue(enum_exists($this->prefix . 'routes'));
            $this->assertFalse(class_exists($this->prefix . 'Unknown'));
        } finally {
            $loader->unregister();
        }

        $this->assertSame($before, spl_autoload_functions());
    }

    #[Test]
    public function it_does_not_follow_links_wrong_case_or_invalid_class_names(): void
    {
        $this->write('base/Utils/Item.php', '<?php throw new \\RuntimeException("Unexpected include");');
        symlink($this->root . DIRECTORY_SEPARATOR . 'base' . DIRECTORY_SEPARATOR . 'Utils', $this->root . DIRECTORY_SEPARATOR . 'base' . DIRECTORY_SEPARATOR . 'Linked');
        $loader = $this->loader([$this->prefix => 'base/']);
        $this->assertNull($loader->findFile($this->prefix . 'utils\\Item'));
        $this->assertNull($loader->findFile($this->prefix . 'Utils\\item'));
        $this->assertNull($loader->findFile($this->prefix . 'Linked\\Item'));
        $this->assertNull($loader->findFile($this->prefix . '..\\Utils\\Item'));
        $this->assertNull($loader->findFile('Other\\Item'));
    }

    #[Test]
    public function it_loads_files_once_after_registering_classes(): void
    {
        $class = $this->prefix . 'Item';
        $key = 'arix_files_' . bin2hex(random_bytes(6));
        $this->write('base/Item.php', '<?php namespace ' . rtrim($this->prefix, '\\') . '; class Item {}');
        $this->write('helpers.php', '<?php $GLOBALS[' . var_export($key, true) . '] = new \\' . $class . '();');
        $loader = $this->loader([$this->prefix => 'base/'], ['helpers.php']);
        $loader->register();

        try {
            $loader->loadFiles();
            $first = $GLOBALS[$key];
            $loader->loadFiles();
            $this->assertInstanceOf($class, $first);
            $this->assertSame($first, $GLOBALS[$key]);
        } finally {
            unset($GLOBALS[$key]);
            $loader->unregister();
        }
    }

    #[Test]
    public function it_validates_every_file_before_executing_any(): void
    {
        $this->write('helpers.php', '<?php throw new \\LogicException("Must not execute");');
        $loader = $this->loader([$this->prefix => 'base/'], ['helpers.php', 'missing.php']);
        $this->expectException(RuntimeException::class);
        $loader->loadFiles();
    }

    #[Test]
    public function it_does_not_follow_a_root_replaced_with_a_link_after_configuration(): void
    {
        $this->write('base/Item.php', '<?php throw new \\LogicException("Replaced root executed");');
        $loader = $this->loader([$this->prefix => 'base/'], ['base/Item.php']);
        $original = "{$this->root}-original";
        rename($this->root, $original);
        symlink($original, $this->root);

        try {
            $this->assertNull($loader->findFile($this->prefix . 'Item'));
            $this->expectException(RuntimeException::class);
            $loader->loadFiles();
        } finally {
            unlink($this->root);
            rename($original, $this->root);
        }
    }

    private function loader(array $prefixes, array $files = []): ClassLoader
    {
        $this->write('.arix.json', json_encode(['version' => '1.0.0', 'autoload' => ['psr-4' => $prefixes, 'files' => $files]], JSON_THROW_ON_ERROR));

        return new ClassLoader(AutoloadConfiguration::read($this->root));
    }

    private function write(string $relative, string $contents): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, $contents);
    }

    #[Test]
    public function it_replaces_the_active_loader_without_accumulating_callbacks(): void
    {
        $before = spl_autoload_functions();
        $first = $this->loader([$this->prefix => 'first/']);
        $second = $this->loader([$this->prefix => 'second/']);
        $first->register();
        $second->register();
        $second->register();
        $this->assertSame($second, ClassLoader::current());
        $this->assertCount(count($before) + 1, spl_autoload_functions());
        $first->unregister();
        $this->assertSame($second, ClassLoader::current());
        $second->unregister();
        $this->assertNull(ClassLoader::current());
        $this->assertSame($before, spl_autoload_functions());
    }

    #[Test]
    public function it_restores_the_previous_loader_after_a_reentrant_registration_fails(): void
    {
        $previous = $this->loader([$this->prefix => 'base/']);
        $previous->register();
        $other = $this->root . DIRECTORY_SEPARATOR . 'other';
        mkdir($other);
        file_put_contents($other . DIRECTORY_SEPARATOR . '.arix.json', '{"version":"1.0.0","autoload":{}}');
        $this->write('throw.php', '<?php (new \\Arix\\Autoload\\ClassLoader(\\Arix\\Autoload\\AutoloadConfiguration::read(__DIR__ . DIRECTORY_SEPARATOR . "other")))->register(); throw new \\RuntimeException("reentrant");');
        $failing = $this->loader([$this->prefix => 'base/'], ['throw.php']);
        $callbacks = spl_autoload_functions();
        $failure = null;

        try {
            $failing->register();
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame('reentrant', $failure->getMessage());
        $this->assertSame($previous, ClassLoader::current());
        $this->assertSame($callbacks, spl_autoload_functions());
    }
}
