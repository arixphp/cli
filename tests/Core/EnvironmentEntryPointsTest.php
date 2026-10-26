<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Application;
use Arix\Config\Repository;
use Arix\Container\Container;
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
        $_ENV['APP_SITE_NAME'] = 'caller';
        $_ENV['APP_URL'] = 'https://caller.test';
        $this->tree->write('.env', "APP_SITE_NAME=local\nAPP_URL=https://myapp.com/local");
        $this->tree->write('app/config/app.php', <<<'PHP'
<?php
declare(strict_types=1);
return ['debug' => \Arix\Runtime\Environment::debug(), 'url' => env('APP_URL'), 'name' => env('APP_SITE_NAME'), 'locales' => ['en'], 'default_locale' => 'en'];
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
        $this->assertSame('caller', $_ENV['APP_SITE_NAME']);
    }

    #[Test]
    public function it_loads_route_configuration_and_restores_after_registration_failures(): void
    {
        $this->assertSame('https://myapp.com/local/about', RouteResolver::fromRoot($this->tree->root)->to('about'));
        $this->assertSame('/about', RouteResolver::fromRoot($this->tree->root)->to('about', [], false));
        $this->assertSame('https://caller.test', $_ENV['APP_URL']);
        $this->tree->write('app/routes.php', '<?php declare(strict_types=1); throw new \RuntimeException("registration failed");');

        try {
            RouteResolver::fromRoot($this->tree->root);
            $this->fail('Registration failure must propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('registration failed', $exception->getMessage());
            $this->assertSame('caller', $_ENV['APP_SITE_NAME']);
        }
    }

    #[Test]
    public function it_reuses_the_outer_scope_for_nested_route_resolution(): void
    {
        Environment::run($this->tree->root, function (): void {
            $this->tree->write('.env', 'malformed');
            $this->assertSame('https://myapp.com/local/about', RouteResolver::fromRoot($this->tree->root)->to('about'));
        });
    }

    #[Test]
    public function it_keeps_direct_application_creation_explicit_about_environment_loading(): void
    {
        $container = new Container();
        Application::create($this->tree->root)->registerConsole($container);
        $this->assertSame('caller', $container->get(Repository::class)->get('app.name'));
        $this->assertSame('local', Environment::run($this->tree->root, function (): string {
            $container = new Container();
            Application::create($this->tree->root)->registerConsole($container);

            return $container->get(Repository::class)->get('app.name');
        }));
    }
}
