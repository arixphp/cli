<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Application;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Exceptions\CoreRuntimeException;
use Arix\Routing\RouteResolver;
use Arix\Runtime\ConsoleRuntime;
use Arix\Runtime\Environment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

class EnvironmentEntryPointsTest extends TestCase
{
    private TempTree $tree;

    private array $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new TempTree();
        $this->tree->applicationManifest();
        $this->env = $_ENV;
        unset($_ENV['APP_SITE_NAME'], $_ENV['APP_URL']);
        $this->tree->write('.env', "APP_SITE_NAME=local\nAPP_URL=https://myapp.com/local");
        $this->tree->write('app/config/app.php', <<<'PHP'
<?php
declare(strict_types=1);
return ['debug' => \Arix\Runtime\Environment::debug(), 'url' => env('APP_URL', 'http://localhost'), 'name' => env('APP_SITE_NAME'), 'locales' => ['en'], 'default_locale' => 'en'];
PHP);
        $this->tree->write('app/views/.keep', '');
        $this->tree->write('app/lang/en.php', '<?php declare(strict_types=1); return [];');
        $this->tree->write('app/routes.php', <<<'PHP'
<?php
declare(strict_types=1);
\Arix\Facades\Route::get('/about', ['App\Controllers\AboutController', 'show'])->name('about');
PHP);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        unset($GLOBALS['arix_environment_task']);
        $this->tree->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_loads_environment_for_console_services_and_scheduled_callbacks(): void
    {
        $this->tree->write('app/services.php', <<<'PHP'
<?php
declare(strict_types=1);
return static function ($container): void {
    if (env('APP_SITE_NAME') !== 'local') { throw new \RuntimeException('Environment missing in services.'); }
};
PHP);
        $this->tree->write('app/schedules.php', <<<'PHP'
<?php
declare(strict_types=1);
return static function ($schedule): void {
    $schedule->call('environment', static function (): void { $GLOBALS['arix_environment_task'] = env('APP_SITE_NAME'); })->everyMinute();
};
PHP);

        $this->assertSame(0, new ConsoleRuntime()->run($this->tree->root));
        $this->assertSame('local', $GLOBALS['arix_environment_task']);
        $this->assertSame('local', $_ENV['APP_SITE_NAME']);
    }

    #[Test]
    public function it_loads_route_configuration_and_propagates_registration_failures(): void
    {
        $this->assertSame('https://myapp.com/local/about', RouteResolver::fromRoot($this->tree->root)->to('about'));
        $this->assertSame('/about', RouteResolver::fromRoot($this->tree->root)->to('about', [], false));
        $this->assertSame('https://myapp.com/local', $_ENV['APP_URL']);
        $this->tree->write('app/routes.php', '<?php declare(strict_types=1); throw new \RuntimeException("registration failed");');

        try {
            RouteResolver::fromRoot($this->tree->root);
            $this->fail('Registration failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('registration failed', $exception->getMessage());
            $this->assertSame('local', $_ENV['APP_SITE_NAME']);
        }
    }

    #[Test]
    public function it_rejects_a_malformed_environment_during_route_resolution(): void
    {
        $this->tree->write('.env', 'malformed');
        $this->expectException(CoreRuntimeException::class);
        RouteResolver::fromRoot($this->tree->root);
    }

    #[Test]
    public function it_resolves_named_routes_without_executing_application_services(): void
    {
        $this->tree->write('app/services.php', <<<'PHP'
<?php
declare(strict_types=1);
throw new \RuntimeException('services were loaded');
PHP);

        $this->assertSame('https://myapp.com/local/about', RouteResolver::fromRoot($this->tree->root)->to('about'));
        $this->assertSame('local', $_ENV['APP_SITE_NAME']);
    }

    #[Test]
    public function it_keeps_direct_application_creation_explicit_about_environment_loading(): void
    {
        $container = new Container();
        Application::create($this->tree->root)->registerConsole($container);
        $this->assertNull($container->get(Repository::class)->get('app.name'));
        Environment::load($this->tree->root);
        $container = new Container();
        Application::create($this->tree->root)->registerConsole($container);
        $this->assertSame('local', $container->get(Repository::class)->get('app.name'));
    }
}
