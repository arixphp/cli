<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Contracts\RequestHandler;
use Arix\Http\Kernel;
use Arix\Http\RequestUrlContext;
use Arix\HttpFoundation\Request;
use Arix\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TempTree;

use function array_slice;

class RequestUrlIntegrationTest extends TestCase
{
    #[Test]
    public function it_uses_one_validated_context_for_views_routes_and_assets(): void
    {
        $tree = new TempTree();
        $tree->write('app/config/app.php', '<?php declare(strict_types=1); return ["debug" => true, "locales" => ["en"], "default_locale" => "en", "base_path" => "/wrong", "url" => "https://configured.test/wrong", "trusted_hosts" => ["^myapp\\.com$", "^other\\.test$"]];');
        $tree->write('app/lang/en.php', '<?php declare(strict_types=1); return [];');
        $tree->write('app/routes.php', '<?php declare(strict_types=1); Arix\Facades\Route::get("/about", [App\Controllers\UrlContextController::class, "show"])->name("about");');
        $tree->write('app/Controllers/UrlContextController.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace App\Controllers;
class UrlContextController {
    public function show(): \Arix\Views\Contracts\View { return view('page'); }
}
PHP);
        $tree->write('app/views/page.arix.php', '{{ url("/about") }}|{{ url()->currentPath() }}|{{ url()->current() }}|{{ route("about") }}|{{ route("about", [], false) }}|{{ style("app.css") }}|{{ script("app.js") }}|{{ media("logo.webp") }}|{{ font("font.woff2") }}');
        $tree->write('styles/app.css', 'body{}');
        $tree->write('scripts/app.js', 'export {};');
        $tree->write('storage/cache/.keep', '');

        try {
            $program = <<<'PHP'
require $argv[1];
$kernel = \Arix\Application::create($argv[2])->kernel();
$results = [];
foreach ([['/sub', '/sub', 'myapp.com'], ['/other', '/other', 'other.test'], ['/sub/index.php', '/sub', 'myapp.com']] as [$routeBase, $assetBase, $host]) {
    $request = new \Arix\HttpFoundation\Request(server: [
        'REQUEST_URI' => "{$routeBase}/about?x=1", 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => '/srv/index.php',
        'SCRIPT_NAME' => "{$assetBase}/index.php", 'HTTP_HOST' => $host, 'HTTPS' => 'on', 'SERVER_PORT' => 443,
    ]);
    $response = $kernel->handle($request);
    $results[] = [$response->getStatusCode(), $response->getContent()];
}
$rejected = new \Arix\HttpFoundation\Request(server: ['REQUEST_URI' => '/about', 'HTTP_HOST' => 'evil.test']);
$results[] = [$kernel->handle($rejected)->getStatusCode(), $kernel->handle($rejected)->getStatusCode()];
echo json_encode($results, JSON_THROW_ON_ERROR);
PHP;
            $autoload = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
            $process = new Process([PHP_BINARY, '-r', $program, $autoload, $tree->root], env: ['XDEBUG_MODE' => 'off']);
            $process->mustRun();
            $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            foreach ([['/sub', '/sub', 'myapp.com'], ['/other', '/other', 'other.test'], ['/sub/index.php', '/sub', 'myapp.com']] as $index => [$routeBase, $assetBase, $host]) {
                $this->assertSame(200, $results[$index][0], $results[$index][1]);
                $parts = explode('|', $results[$index][1]);
                $this->assertSame(["https://{$host}{$routeBase}/about", '/about', "https://{$host}{$routeBase}/about", "https://{$host}{$routeBase}/about", '/about'], array_slice($parts, 0, 5));
                $this->assertStringStartsWith("https://{$host}{$assetBase}/styles/app.css?v=", $parts[5]);
                $this->assertStringStartsWith("https://{$host}{$assetBase}/scripts/app.js?v=", $parts[6]);
                $this->assertSame("https://{$host}{$assetBase}/media/logo.webp", $parts[7]);
                $this->assertSame("https://{$host}{$assetBase}/fonts/font.woff2", $parts[8]);
            }

            $this->assertSame([400, 400], $results[3]);
        } finally {
            $tree->cleanup();
        }
    }

    #[Test]
    public function it_recomputes_context_before_configuration_on_every_dispatch(): void
    {
        $handler = new class() implements RequestHandler
        {
            public function handle(Request $request, Container $container): Response
            {
                $context = $request->attributes->get('_arix_url_context');

                return new Response(json_encode([$request->attributes->get('_arix_path'), $context->baseUrl], JSON_THROW_ON_ERROR));
            }
        };
        $kernel = new Kernel($handler, configure: function (Container $container): void {
            $request = $container->get(Request::class);
            $this->assertInstanceOf(RequestUrlContext::class, $request->attributes->get('_arix_url_context'));
            $this->assertSame('/about', $request->attributes->get('_arix_path'));
        });

        foreach (['', '/one', '/two'] as $mount) {
            $request = new Request(attributes: ['_arix_path' => '/forged', '_arix_url_context' => 'forged'], server: [
                'REQUEST_URI' => "{$mount}/about", 'REQUEST_METHOD' => 'GET', 'SCRIPT_NAME' => "{$mount}/index.php", 'SCRIPT_FILENAME' => '/srv/index.php',
            ]);
            $response = $kernel->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['/about', $mount], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
        }
    }

    #[Test]
    public function it_always_detects_the_mount_without_partial_prefix_matches(): void
    {
        $handler = new class() implements RequestHandler
        {
            public function handle(Request $request, Container $container): Response
            {

                return new Response($request->attributes->get('_arix_path'));
            }
        };
        $request = new Request(server: ['REQUEST_URI' => '/sub/about', 'SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/index.php']);
        $this->assertSame('/about', new Kernel($handler)->handle($request)->getContent());
        $request = new Request(server: ['REQUEST_URI' => '/submarine/about', 'SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/srv/index.php']);
        $this->assertSame('/submarine/about', new Kernel($handler)->handle($request)->getContent());
    }
}
