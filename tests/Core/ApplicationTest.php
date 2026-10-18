<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Application;
use Arix\Http\MiddlewareConfiguration;
use Arix\Http\Kernel;
use Arix\HttpFoundation\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function strlen;

class ApplicationTest extends TestCase
{
    #[Test]
    public function it_builds_a_kernel_with_fluent_global_middleware_configuration(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("hello"); }');
        $this->routes('/', $class);

        $kernel = Application::configure($this->root)
            ->withMiddleware(static function (MiddlewareConfiguration $middleware): void {
                $middleware->append('App\\Middleware\\SetLocale');
            })
            ->create();

        $this->assertInstanceOf(Kernel::class, $kernel);
    }
    private string $root;

    private string $controllers;

    protected function setUp(): void
    {
        parent::setUp();
        $id = 'Site' . bin2hex(random_bytes(6));
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-application-' . bin2hex(random_bytes(8));
        $this->controllers = "App\\Controllers\\{$id}";
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config', 0755, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . $id, 0755, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views', 0755, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lang', 0755, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', 0755, true);
        $this->config([]);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    #[Test]
    public function it_rejects_missing_roots(): void
    {
        $this->expectException(RuntimeException::class);
        Application::create($this->root . DIRECTORY_SEPARATOR . 'missing');
    }

    #[Test]
    public function it_rejects_roots_without_configuration(): void
    {
        $this->remove($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config');
        $this->expectException(RuntimeException::class);
        Application::create($this->root);
    }

    #[Test]
    public function it_rejects_roots_without_routes(): void
    {
        $this->expectException(RuntimeException::class);
        Application::create($this->root);
    }

    #[Test]
    public function it_rejects_linked_roots(): void
    {
        $link = $this->root . DIRECTORY_SEPARATOR . 'linked';
        symlink($this->root . DIRECTORY_SEPARATOR . 'app', $link);
        $this->expectException(RuntimeException::class);
        Application::create($link);
    }

    #[Test]
    public function it_serves_routes_without_a_services_file(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("hello"); }');
        $this->routes('/', $class);
        $kernel = Application::create($this->root)->kernel();
        $this->assertInstanceOf(Kernel::class, $kernel);
        $response = $kernel->handle(Request::create('/'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $response->getContent());
    }

    #[Test]
    public function it_prefers_explicit_bindings(): void
    {
        $class = $this->controller('public function __construct(private \\stdClass $dependency) {} public function show(): Response { return new Response("bound"); }');
        $this->routes('/', $class);
        $this->services("\$container->bind(\\{$class}::class, static fn (): \\{$class} => new \\{$class}(new \\stdClass));");
        $response = Application::create($this->root)->kernel()->handle(Request::create('/'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('bound', $response->getContent());
    }

    #[Test]
    public function it_rejects_invalid_services_files(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("hello"); }');
        $this->routes('/', $class);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services.php', "<?php\ndeclare(strict_types=1);\nreturn [];\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('registration closure');
        Application::create($this->root);
    }

    #[Test]
    public function it_rejects_linked_services_files(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("hello"); }');
        $this->routes('/', $class);
        $target = $this->root . DIRECTORY_SEPARATOR . 'services-target.php';
        file_put_contents($target, "<?php\ndeclare(strict_types=1);\nreturn static function (\\Arix\\Container\\Container \$container): void {};\n");
        symlink($target, $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('linked');
        Application::create($this->root);
    }

    #[Test]
    public function it_rejects_unreadable_services_files(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("hello"); }');
        $this->routes('/', $class);
        $path = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services.php';
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\nreturn static function (\\Arix\\Container\\Container \$container): void {};\n");
        chmod($path, 0000);

        try {
            if (is_readable($path)) {
                $this->markTestSkipped('This filesystem/user does not enforce unreadable fixture permissions.');
            }

            $this->expectException(RuntimeException::class);
            Application::create($this->root);
        } finally {
            chmod($path, 0600);
        }
    }

    #[Test]
    public function it_rejects_routes_replaced_between_create_and_request(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("hello"); }');
        $this->routes('/', $class);
        $this->config(['debug' => true]);
        $application = Application::create($this->root);
        $target = $this->root . DIRECTORY_SEPARATOR . 'routes-target.php';
        rename($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php', $target);
        symlink($target, $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php');
        $previous = ini_get('error_log');
        ini_set('error_log', $this->root . DIRECTORY_SEPARATOR . 'errors.log');

        try {
            $response = $application->kernel()->handle(Request::create('/'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('linked', $response->getContent());
    }

    #[Test]
    public function it_rejects_services_replaced_between_create_and_request(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("hello"); }');
        $this->routes('/', $class);
        $this->services('');
        $this->config(['debug' => true]);
        $application = Application::create($this->root);
        $target = $this->root . DIRECTORY_SEPARATOR . 'services-target.php';
        rename($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services.php', $target);
        symlink($target, $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services.php');
        $previous = ini_get('error_log');
        ini_set('error_log', $this->root . DIRECTORY_SEPARATOR . 'errors.log');

        try {
            $response = $application->kernel()->handle(Request::create('/'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('linked', $response->getContent());
    }

    #[Test]
    public function it_applies_the_configured_base_path(): void
    {
        $class = $this->controller('public function show(): Response { return new Response("scoped"); }');
        $this->routes('/page', $class);
        $this->config(['base_path' => '/site']);
        $kernel = Application::create($this->root)->kernel();
        $this->assertSame('scoped', $kernel->handle(Request::create('/site/page'))->getContent());
        $this->assertSame(404, $kernel->handle(Request::create('/page'))->getStatusCode());
    }

    #[Test]
    public function it_builds_a_fresh_container_per_request(): void
    {
        $class = $this->controller('public static int $constructed = 0; public function __construct() { self::$constructed++; } public function show(): Response { return new Response("fresh"); }');
        $this->routes('/', $class);
        $this->services("\$container->bind(\\{$class}::class, static fn (): \\{$class} => new \\{$class});");
        $kernel = Application::create($this->root)->kernel();
        $kernel->handle(Request::create('/'));
        $kernel->handle(Request::create('/'));
        $this->assertSame(2, $class::$constructed);
    }

    /** @param array<string, mixed> $overrides */
    private function config(array $overrides): void
    {
        $app = array_replace([
            'debug' => false,
            'base_path' => '',
            'locales' => ['en'],
            'default_locale' => 'en',
            'name' => 'Site',
            'max_body_bytes' => 1048576,
        ], $overrides);
        $config = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config';
        file_put_contents($config . DIRECTORY_SEPARATOR . 'app.php', "<?php\ndeclare(strict_types=1);\nreturn " . var_export($app, true) . ";\n");
        file_put_contents($config . DIRECTORY_SEPARATOR . 'cache.php', "<?php\ndeclare(strict_types=1);\nreturn ['path' => 'storage/cache', 'version' => '1'];\n");
    }

    private function controller(string $body): string
    {
        $id = substr($this->controllers, strlen('App\\Controllers\\'));
        $class = "{$this->controllers}\\SiteController";
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'SiteController.php',
            "<?php\ndeclare(strict_types=1);\nnamespace {$this->controllers};\nuse Arix\\HttpFoundation\\Response;\nclass SiteController { {$body} }\n",
        );

        return $class;
    }

    private function routes(string $path, string $class): void
    {
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php',
            "<?php\ndeclare(strict_types=1);\nuse Arix\\Facades\\Route;\nRoute::get('{$path}', [\\{$class}::class, 'show']);\n",
        );
    }

    private function services(string $body): void
    {
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'services.php',
            "<?php\ndeclare(strict_types=1);\nuse Arix\\Container\\Container;\nreturn static function (Container \$container): void { {$body} };\n",
        );
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
