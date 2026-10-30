<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TempTree;
use Tests\Support\TestApplication;

class StandaloneEnvironmentTest extends TestCase
{
    #[Test]
    public function it_redacts_environment_values_from_logs_when_exception_arguments_are_enabled(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $tree->write('.env', "APP_DEBUG=true\nAPP_TOKEN=\"private-token-sentinel\"invalid");
        $autoload = TestApplication::prepareCore($tree->root);
        $program = <<<'PHP'
require $argv[1];
$logs = [];
$runtime = new \Arix\Runtime\HttpRuntime($argv[2], static function (string $message) use (&$logs): void { $logs[] = $message; });
$response = $runtime->handle(static function (): never { throw new \RuntimeException('Bootstrap must not execute.'); }, 'fpm-fcgi');
echo json_encode([$response->getStatusCode(), $logs, $response->getContent()], JSON_THROW_ON_ERROR);
PHP;

        try {
            $process = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=256', '-r', $program, $autoload, $tree->root], env: ['XDEBUG_MODE' => 'off']);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(500, $result[0]);
            $this->assertNotEmpty($result[1]);
            $this->assertStringNotContainsString('private-token-sentinel', $process->getOutput() . $process->getErrorOutput());
        } finally {
            $tree->cleanup();
        }
    }

    #[Test]
    public function it_loads_only_the_explicit_application_environment_without_composer(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $tree->write('.env', "APP_STANDALONE_TEST=file\nAPP_DEBUG=\"false\"");
        $autoload = TestApplication::prepareCore($tree->root);
        $program = <<<'PHP'
require $argv[1];
$_ENV['APP_STANDALONE_TEST'] = 'caller';
$during = \Arix\Runtime\Environment::run($argv[2], static fn () => [env('APP_STANDALONE_TEST'), env('APP_DEBUG'), \Arix\Runtime\Environment::debug()]);
echo json_encode([$during, env('APP_STANDALONE_TEST'), class_exists('Illuminate\Container\Container', false)], JSON_THROW_ON_ERROR);
PHP;

        try {
            $process = new Process([PHP_BINARY, '-r', $program, $autoload, $tree->root], dirname(__DIR__, 2), ['XDEBUG_MODE' => 'off']);
            $process->mustRun();
            $this->assertSame([['file', 'false', false], 'caller', false], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $tree->cleanup();
        }
    }

    #[Test]
    public function it_bootstraps_application_configuration_and_controller_in_the_same_environment(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $tree->write('.env', 'APP_STANDALONE_TEST=from-file');
        $tree->write('app/views/.keep', '');
        $tree->write('app/lang/en.php', '<?php declare(strict_types=1); return [];');
        $tree->write('app/config/app.php', <<<'PHP'
<?php
declare(strict_types=1);
return ['debug' => \Arix\Runtime\Environment::debug(), 'base_path' => '', 'locales' => ['en'], 'default_locale' => 'en'];
PHP);
        $tree->write('app/routes.php', <<<'PHP'
<?php
declare(strict_types=1);
\Arix\Facades\Route::get('/', [\App\Controllers\SiteController::class, 'show']);
PHP);
        $tree->write('app/Controllers/SiteController.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Controllers;
class SiteController {
    public function show(): \Arix\HttpFoundation\Response {
        return new \Arix\HttpFoundation\Response(env('APP_STANDALONE_TEST'));
    }
}
PHP);
        $tree->write('app/bootstrap.php', '<?php declare(strict_types=1); return \Arix\Application::configure(dirname(__DIR__))->create();');
        $autoload = TestApplication::prepareCore($tree->root);
        $program = <<<'PHP'
require $argv[1];
$_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_HOST' => 'localhost'];
$root = $argv[2];
$runtime = new \Arix\Runtime\HttpRuntime(applicationRoot: $root);
$response = $runtime->handle(static fn (): \Arix\Http\Kernel => require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php', 'fpm-fcgi');
echo json_encode([$response->getStatusCode(), $response->getContent()], JSON_THROW_ON_ERROR);
PHP;

        try {
            $process = new Process([PHP_BINARY, '-r', $program, $autoload, $tree->root], env: ['XDEBUG_MODE' => 'off']);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(200, $result[0], $process->getErrorOutput());
            $this->assertSame('from-file', $result[1]);
        } finally {
            $tree->cleanup();
        }
    }

    #[Test]
    public function it_rejects_the_simplified_entry_point_on_cli_without_loading_a_malformed_file(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $tree->write('.env', 'invalid secret');
        $autoload = TestApplication::prepareCore($tree->root);
        $script = $tree->write('index.php', '<?php declare(strict_types=1); require $argv[1]; exit((new \Arix\Runtime\HttpRuntime(applicationRoot: __DIR__))->run(static function () { echo "BOOTSTRAP"; }));');

        try {
            $process = new Process([PHP_BINARY, $script, $autoload], env: ['XDEBUG_MODE' => 'off']);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertSame('', $process->getOutput());
            $this->assertStringContainsString('not supported', $process->getErrorOutput());
            $this->assertStringNotContainsString('secret', $process->getErrorOutput());
            $this->assertStringNotContainsString('environment', $process->getErrorOutput());
        } finally {
            $tree->cleanup();
        }
    }
}
