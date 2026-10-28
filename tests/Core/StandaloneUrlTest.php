<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TempTree;
use Tests\Support\TestApplication;

class StandaloneUrlTest extends TestCase
{
    #[Test]
    public function it_uses_the_target_application_url_in_console_and_route_resolution(): void
    {
        $tree = new TempTree();
        $tree->applicationManifest();
        $tree->write('.env', 'APP_URL=https://myapp.com/subdirectory');
        $tree->write('app/config/app.php', '<?php declare(strict_types=1); return ["url" => env("APP_URL"), "locales" => ["en"], "default_locale" => "en"];');
        $tree->write('app/views/probe.php', '<?php echo url("/about");');
        $tree->write('app/lang/en.php', '<?php declare(strict_types=1); return [];');
        $tree->write('app/routes.php', '<?php declare(strict_types=1); \Arix\Facades\Route::get("/about", ["App\\Controllers\\UnusedController", "show"])->name("about");');
        $tree->write('app/schedules.php', <<<'PHP'
<?php
declare(strict_types=1);
return static function ($schedule): void {
    $schedule->call('url', static function (): void {
        $engine = \Arix\Container\ContainerContext::current()->get(\Arix\Views\TemplateEngine::class);
        $GLOBALS['url_result'] = [url('/about'), route('about'), route('about', [], false), $engine->render('probe')];
    })->everyMinute();
};
PHP);
        $program = <<<'PHP'
require $argv[1];
$root = $argv[2];
putenv('APP_URL=https://process.test/ignored');
$_ENV['APP_URL'] = 'https://caller.test';
$first = \Arix\Routing\RouteResolver::fromRoot($root)->to('about');
ob_start();
$status = (new \Arix\Runtime\ConsoleRuntime())->run($root);
$consoleOutput = ob_get_clean();
$console = $GLOBALS['url_result'] ?? null;
file_put_contents($root . DIRECTORY_SEPARATOR . '.env', 'APP_URL=https://other.test/next');
$second = \Arix\Routing\RouteResolver::fromRoot($root)->to('about');
unlink($root . DIRECTORY_SEPARATOR . '.env');
$third = \Arix\Routing\RouteResolver::fromRoot($root)->to('about');
echo "\nURL_RESULT:" . json_encode([$status, $first, $console, $second, $third, env('APP_URL'), class_exists('Illuminate\Container\Container', false), $consoleOutput], JSON_THROW_ON_ERROR);
PHP;

        try {
            $autoload = TestApplication::prepareCore($tree->root);
            $process = new Process([PHP_BINARY, '-r', $program, $autoload, $tree->root], env: ['XDEBUG_MODE' => 'off']);
            $process->mustRun();
            $result = json_decode(explode("\nURL_RESULT:", $process->getOutput(), 2)[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $result[0], $process->getErrorOutput() . $result[7]);
            $this->assertSame('https://myapp.com/subdirectory/about', $result[1]);
            $this->assertSame(['https://myapp.com/subdirectory/about', 'https://myapp.com/subdirectory/about', '/about', 'https://myapp.com/subdirectory/about'], $result[2]);
            $this->assertSame('https://other.test/next/about', $result[3]);
            $this->assertSame('https://caller.test/about', $result[4]);
            $this->assertSame('https://caller.test', $result[5]);
            $this->assertFalse($result[6]);
        } finally {
            $tree->cleanup();
        }
    }
}
