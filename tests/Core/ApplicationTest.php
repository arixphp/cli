<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Application;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Http\Kernel;
use Arix\Http\MiddlewareConfiguration;
use Arix\HttpFoundation\Request;
use Arix\Localization\Translator;
use Arix\Routing\RouteRegistry;
use Arix\Scheduling\Schedule;
use Arix\Storage\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

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

    #[Test]
    public function it_uses_a_global_middleware_locale_before_registering_translations(): void
    {
        $class = $this->controller('public function show(): \\Arix\\Views\\Contracts\\View { $container = \\Arix\\Container\\ContainerContext::current(); return new \\Arix\\Views\\View("home", ["locale" => $container->get(\\Arix\\Config\\Repository::class)->get("app.locale"), "title" => $container->get(\\Arix\\Localization\\Translator::class)->get("home.title")]); }');
        $this->routes('/', $class);
        $this->config(['locales' => ['en', 'es'], 'default_locale' => 'en']);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Middleware', 0755, true);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Middleware' . DIRECTORY_SEPARATOR . 'SetLocale.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Middleware;
use Arix\Contracts\Middleware;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;
class SetLocale implements Middleware { public function handle(Request $request, Closure $next): Response { $locales = \Arix\Container\ContainerContext::current()->get(\Arix\Config\Repository::class)->get('app.locales'); $request->setLocale($request->getPreferredLanguage($locales)); return $next($request); } }
PHP);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en.php', "<?php return ['home' => ['title' => 'Welcome']];");
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'es.php', "<?php return ['home' => ['title' => 'Bienvenido']];");
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'home.arix.php', '<html lang="{{ $locale }}">{{ $title }}</html>');

        $alias = $this->root . DIRECTORY_SEPARATOR . 'current';
        symlink($this->root, $alias);
        $kernel = Application::configure($alias)->withMiddleware(static function (MiddlewareConfiguration $middleware): void {
            $middleware->append('App\\Middleware\\SetLocale');
        })->create();

        $spanish = Request::create('/', 'GET', server: ['HTTP_ACCEPT_LANGUAGE' => 'es-MX,es;q=0.9']);
        $this->assertSame('<html lang="es">Bienvenido</html>', $kernel->handle($spanish)->getContent());
        $this->assertSame('<html lang="en">Welcome</html>', $kernel->handle(Request::create('/'))->getContent());
    }

    #[Test]
    public function it_resolves_explicitly_bound_global_middleware_dependencies(): void
    {
        $class = $this->controller('public function show(\Arix\HttpFoundation\Request $request): Response { return new Response($request->attributes->get("token")); }');
        $this->routes('/', $class);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Middleware', 0755, true);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Middleware' . DIRECTORY_SEPARATOR . 'RequiresDependency.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Middleware;
use Arix\Contracts\Middleware;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use Closure;
class RequiresDependency implements Middleware { public function __construct(private string $token) {} public function handle(Request $request, Closure $next): Response { $request->attributes->set('token', $this->token); return $next($request); } }
PHP);
        $this->services("\$container->instance(\\App\\Middleware\\RequiresDependency::class, new \\App\\Middleware\\RequiresDependency('bound'));");

        $kernel = Application::configure($this->root)->withMiddleware(static function (MiddlewareConfiguration $middleware): void {
            $middleware->append('App\\Middleware\\RequiresDependency');
        })->create();

        $this->assertSame('bound', $kernel->handle(Request::create('/'))->getContent());
    }

    private string $root;

    private string $controllers;

    #[Test]
    #[DataProvider('caseMismatchedResources')]
    public function it_never_executes_case_mismatched_resource_files(string $resource): void
    {
        $this->routes('/', $this->controller('public function show(): Response { return new Response("safe"); }'));
        $marker = $this->root . DIRECTORY_SEPARATOR . 'executed';
        $path = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $resource;
        file_put_contents($path, '<?php declare(strict_types=1); file_put_contents(' . var_export($marker, true) . ', "yes"); return static function (): void {};');
        rename($path, dirname($path) . DIRECTORY_SEPARATOR . ucfirst($resource));

        try {
            $application = Application::create($this->root);
            $application->kernel()->handle(Request::create('/'));
        } catch (RuntimeException) {
            // A required exact-case resource is missing.
        } finally {
            $this->assertFileDoesNotExist($marker);
        }
    }

    public static function caseMismatchedResources(): array
    {

        return [['routes.php'], ['services.php']];
    }

    #[Test]
    public function it_keeps_http_code_translations_and_views_in_the_selected_release_without_composer(): void
    {
        $id = 'Pinned' . bin2hex(random_bytes(6));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . "{$id}.php", "<?php declare(strict_types=1); namespace App\\Services; class {$id} { public static function message(): string { return 'release-A'; } }");
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en.php', '<?php declare(strict_types=1); return ["title" => "translation-A"];');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'pinned.arix.php', '{{ $message }}|{{ $title }}');
        $class = $this->controller('public function show(): \\Arix\\Views\\Contracts\\View { return new \\Arix\\Views\\View("pinned", ["message" => \\App\\Services\\' . $id . '::message(), "title" => \\Arix\\Container\\ContainerContext::current()->get(\\Arix\\Localization\\Translator::class)->get("title")]); }');
        $this->routes('/', $class);
        $alias = $this->root . DIRECTORY_SEPARATOR . 'current';
        symlink($this->root, $alias);
        $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        $program = <<<'PHP'
require $argv[1];
$kernel = \Arix\Application::create($argv[2])->kernel();
unlink($argv[2]);
symlink($argv[3], $argv[2]);
if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
$response = $kernel->handle(\Arix\HttpFoundation\Request::create('/'));
echo $response->getContent();
exit($response->getStatusCode() === 200 ? 0 : 1);
PHP;
        $process = new Process([PHP_BINARY, '-r', $program, $autoload, $alias, $this->root . DIRECTORY_SEPARATOR . 'app']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('release-A|translation-A', $process->getOutput());
    }

    #[Test]
    #[DataProvider('linkedCodeDirectories')]
    public function it_rejects_linked_code_directories_before_loading_php(string $directory): void
    {
        $this->routes('/', $this->controller('public function show(): Response { return new Response("safe"); }'));
        $marker = $this->root . DIRECTORY_SEPARATOR . 'executed';

        if ($directory === 'Middleware') {
            mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $directory);
            file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . 'Unsafe.php', '<?php declare(strict_types=1); file_put_contents(' . var_export($marker, true) . ', "yes");');
        }

        $path = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $directory;
        rename($path, "{$path}-saved");
        symlink("{$path}-saved", $path);
        $kernel = Application::create($this->root)->kernel($directory === 'Middleware' ? ['App\\Middleware\\Unsafe'] : []);
        $this->assertSame(500, $kernel->handle(Request::create('/'))->getStatusCode());
        $this->assertFileDoesNotExist($marker);
    }

    public static function linkedCodeDirectories(): array
    {

        return [['Controllers'], ['Middleware']];
    }

    #[Test]
    #[DataProvider('linkedResources')]
    public function it_rejects_linked_resources_before_they_execute(string $resource): void
    {
        $class = $this->controller('public function show(): Response { return new Response("safe"); }');
        $this->routes('/', $class);
        $marker = $this->root . DIRECTORY_SEPARATOR . 'executed';
        $file = $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $resource);
        $target = $this->root . DIRECTORY_SEPARATOR . 'payload.php';
        file_put_contents($target, '<?php declare(strict_types=1); file_put_contents(' . var_export($marker, true) . ', "executed"); return [];');

        if (is_file($file)) {
            unlink($file);
        }

        symlink($target, $file);

        try {
            $application = Application::create($this->root);

            if ($resource === 'schedules.php') {
                $application->schedules(new Schedule());
            } else {
                $response = $application->kernel()->handle(Request::create('/'));
                $this->assertSame(500, $response->getStatusCode());
            }
        } catch (RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        } finally {
            $this->assertFileDoesNotExist($marker);
        }
    }

    public static function linkedResources(): array
    {

        return [['config/custom.php'], ['routes.php'], ['services.php'], ['schedules.php']];
    }

    #[Test]
    public function it_rejects_a_linked_app_directory_before_loading_configuration(): void
    {
        $this->routes('/', $this->controller('public function show(): Response { return new Response("safe"); }'));
        rename($this->root . DIRECTORY_SEPARATOR . 'app', $this->root . DIRECTORY_SEPARATOR . 'saved');
        symlink($this->root . DIRECTORY_SEPARATOR . 'saved', $this->root . DIRECTORY_SEPARATOR . 'app');
        $this->expectException(RuntimeException::class);
        Application::create($this->root);
    }

    #[Test]
    #[DataProvider('optionalResources')]
    public function it_rejects_broken_optional_links(string $resource): void
    {
        $this->routes('/', $this->controller('public function show(): Response { return new Response("safe"); }'));
        symlink($this->root . DIRECTORY_SEPARATOR . 'missing', $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $resource);
        $this->expectException(RuntimeException::class);
        $application = Application::create($this->root);
        $application->schedules(new Schedule());
    }

    public static function optionalResources(): array
    {

        return [['services.php'], ['schedules.php']];
    }

    #[Test]
    public function it_rechecks_app_components_before_request_execution(): void
    {
        $this->routes('/', $this->controller('public function show(): Response { return new Response("safe"); }'));
        $application = Application::create($this->root);
        rename($this->root . DIRECTORY_SEPARATOR . 'app', $this->root . DIRECTORY_SEPARATOR . 'saved');
        symlink($this->root . DIRECTORY_SEPARATOR . 'saved', $this->root . DIRECTORY_SEPARATOR . 'app');
        $this->assertSame(500, $application->kernel()->handle(Request::create('/'))->getStatusCode());
    }

    #[Test]
    public function it_autoloads_application_dependencies_in_configuration_services_and_controllers(): void
    {
        $id = 'Helper' . bin2hex(random_bytes(6));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Utils');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Utils' . DIRECTORY_SEPARATOR . "{$id}.php", "<?php\ndeclare(strict_types=1);\nnamespace App\\Utils;\nclass {$id} { public static function message(): string { return 'autoloaded'; } }\n");
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'custom.php', "<?php\ndeclare(strict_types=1);\nreturn ['message' => \\App\\Utils\\{$id}::message()];\n");
        $this->services("\\App\\Utils\\{$id}::message();");
        $class = $this->controller("public function show(): Response { return new Response(\\App\\Utils\\{$id}::message()); }");
        $this->routes('/', $class);

        $response = Application::create($this->root)->kernel()->handle(Request::create('/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('autoloaded', $response->getContent());
    }

    #[Test]
    public function it_autoloads_a_dependency_first_used_during_controller_execution(): void
    {
        $id = 'Lazy' . bin2hex(random_bytes(6));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . "{$id}.php", "<?php\ndeclare(strict_types=1);\nnamespace App\\Services;\nclass {$id} { public static function message(): string { return 'lazy'; } }\n");
        $class = $this->controller("public function show(): Response { return new Response(\\App\\Services\\{$id}::message()); }");
        $this->routes('/', $class);
        $alias = $this->root . DIRECTORY_SEPARATOR . 'current';
        symlink($this->root, $alias);
        $kernel = Application::create($alias)->kernel();
        unlink($alias);
        symlink($this->root . DIRECTORY_SEPARATOR . 'app', $alias);

        $this->assertFalse(class_exists("App\\Services\\{$id}", false));
        $this->assertSame('lazy', $kernel->handle(Request::create('/'))->getContent());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $id = 'Site' . bin2hex(random_bytes(6));
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-application-' . bin2hex(random_bytes(8));
        $this->controllers = "App\\Controllers\\{$id}";
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config', 0755, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . $id, 0755, true);
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
    public function it_registers_the_console_services_without_loading_the_routes(): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php', "<?php\ndeclare(strict_types=1);\n\\Arix\\Facades\\Route::get('/', static fn (): \\Arix\\HttpFoundation\\Response => new \\Arix\\HttpFoundation\\Response('home'));\n");
        $container = new Container();

        ContainerContext::run($container, function () use ($container): void {
            Application::create($this->root)->registerConsole($container);
        });

        $this->assertTrue($container->has(Repository::class));
        $this->assertTrue($container->has(Storage::class));
        $this->assertTrue($container->has(Translator::class));
        $this->assertSame([], $container->get(RouteRegistry::class)->methods());
    }

    #[Test]
    public function it_registers_the_application_schedules(): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php', "<?php\ndeclare(strict_types=1);\n");
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'schedules.php', "<?php\ndeclare(strict_types=1);\nreturn static function (\\Arix\\Scheduling\\Schedule \$schedule): void { \$schedule->call('report:daily', static fn (): null => null); };\n");
        $schedule = new Schedule();

        Application::create($this->root)->schedules($schedule);

        $this->assertTrue($schedule->has('report:daily'));
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
            $this->root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . 'SiteController.php',
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
