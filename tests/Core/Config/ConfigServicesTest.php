<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Arix\Config\ConfigServices;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Contracts\RequestHandler;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ConfigServicesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-config-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_loads_named_arrays_at_registration_and_keeps_a_shared_snapshot(): void
    {
        $counter = $this->directory . DIRECTORY_SEPARATOR . 'loads';
        $file = $this->directory . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents($file, '<?php file_put_contents(' . var_export($counter, true) . ", 'loaded'); return ['name' => 'Site', 'nested' => ['enabled' => false], 'nullable' => null];");
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'database.php', "<?php return ['default' => 'sqlite'];");
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'ignored.txt', '<?php throw new RuntimeException;');
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'nested');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'ignored.php', '<?php throw new RuntimeException;');
        $container = new Container();

        $repository = ConfigServices::register($container, $this->directory);

        $this->assertFileExists($counter);
        file_put_contents($file, "<?php throw new RuntimeException('must not reload');");
        $this->assertSame($repository, $container->get(Repository::class));
        $this->assertSame('Site', $repository->get('app.name'));
        $this->assertSame(false, $repository->get('app.nested.enabled', true));
        $this->assertSame(null, $repository->get('app.nullable', 'fallback'));
        $this->assertSame('sqlite', $repository->get('database.default'));
        $this->assertSame('fallback', $repository->get('missing', 'fallback'));
        $this->assertSame(['app', 'database'], array_keys($repository->get()));
    }

    #[Test]
    public function it_registers_an_empty_repository_when_the_directory_is_missing(): void
    {
        $container = new Container();
        ConfigServices::register($container, $this->directory . DIRECTORY_SEPARATOR . 'missing');

        $this->assertSame([], $container->get(Repository::class)->get());
        $this->assertSame('fallback', $container->get(Repository::class)->get('app.name', 'fallback'));
    }

    #[Test]
    public function it_loads_an_empty_directory(): void
    {
        $container = new Container();
        ConfigServices::register($container, $this->directory);

        $this->assertSame([], $container->get(Repository::class)->get());
    }

    #[Test]
    #[DataProvider('invalidFiles')]
    public function it_identifies_invalid_configuration_files(string $name, string $code): void
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($file, $code);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($file);

        ConfigServices::register(new Container(), $this->directory);
    }

    public static function invalidFiles(): array
    {

        return [
            ['app.php', '<?php return "invalid";'],
            ['app.php', '<?php return ["nested" => ["literal.dot" => true]];'],
            ['app.name.php', '<?php return [];'],
            ['broken.php', '<?php return [;'],
            ['broken.php', '<?php throw new RuntimeException("broken");'],
        ];
    }

    #[Test]
    public function it_rejects_a_file_in_place_of_the_configuration_directory(): void
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'config';
        file_put_contents($file, 'not a directory');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs($file);

        ConfigServices::register(new Container(), $file);
    }

    #[Test]
    public function it_shares_the_bootstrap_snapshot_across_request_containers(): void
    {
        $file = $this->directory . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents($file, "<?php return ['name' => 'Bootstrapped'];");
        $repository = ConfigServices::register(new Container(), $this->directory);
        $handler = new class() implements RequestHandler
        {
            public function handle(Request $request, Container $container): Response
            {

                return new Response($container->get(Repository::class)->get('app.name'));
            }
        };
        $kernel = new Kernel($handler, configure: static function (Container $container) use ($repository): void {
            $container->instance(Repository::class, $repository);
        });
        file_put_contents($file, "<?php throw new RuntimeException('must not reload');");

        $this->assertSame('Bootstrapped', $kernel->handle(Request::create('/one'))->getContent());
        $this->assertSame('Bootstrapped', $kernel->handle(Request::create('/two'))->getContent());
    }

    #[Test]
    public function it_executes_configuration_in_the_bootstrap_container_context(): void
    {
        $container = new Container();
        $container->instance('settings', (object) ['name' => 'Context']);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'app.php', "<?php return ['name' => Arix\\Container\\ContainerContext::current()->get('settings')->name];");
        $repository = ConfigServices::register($container, $this->directory);

        $this->assertSame('Context', $repository->get('app.name'));
        $this->expectException(\LogicException::class);
        ContainerContext::current();
    }

    #[Test]
    public function it_resolves_the_default_directory_relative_to_the_published_core(): void
    {
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Config';
        $configuration = $this->directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config';
        mkdir($core, 0755, true);
        mkdir($configuration, 0755, true);
        file_put_contents($configuration . DIRECTORY_SEPARATOR . 'app.php', "<?php return ['name' => 'Published'];");
        $source = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core';
        copy($source . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Loader.php', $core . DIRECTORY_SEPARATOR . 'Loader.php');
        $program = 'require ' . var_export($source . DIRECTORY_SEPARATOR . 'autoload.php', true) . '; require ' . var_export($core . DIRECTORY_SEPARATOR . 'Loader.php', true) . '; echo (new Arix\\Config\\Loader())->load()->get("app.name");';
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('Published', $output);
    }

    #[Test]
    public function it_exposes_configuration_without_views_or_composer_from_a_bootstrap_relative_path(): void
    {
        $app = $this->directory . DIRECTORY_SEPARATOR . 'app';
        $bootstrap = $this->directory . DIRECTORY_SEPARATOR . 'bootstrap';
        mkdir($app);
        mkdir($app . DIRECTORY_SEPARATOR . 'config');
        mkdir($bootstrap);
        file_put_contents($app . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', "<?php return ['name' => 'Standalone'];");
        $autoload = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        file_put_contents($bootstrap . DIRECTORY_SEPARATOR . 'app.php', '<?php require ' . var_export($autoload, true) . '; ' . <<<'PHP'
            $container = new Arix\Container\Container();
            Arix\Config\ConfigServices::register($container, __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'config');
            echo Arix\Container\ContainerContext::run($container, static function (): string {
                if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
                if (config() !== ['app' => ['name' => 'Standalone']]) { exit(3); }
                return config('app.name').'|'.config('missing', 'fallback');
            });
            PHP);
        $process = proc_open([PHP_BINARY, $bootstrap . DIRECTORY_SEPARATOR . 'app.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('Standalone|fallback', $output);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }

            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
