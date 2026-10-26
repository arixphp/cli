<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Application;
use Arix\Container\ContainerContext;
use Arix\HttpFoundation\Request;
use Arix\Runtime\HttpRuntime;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\CoreUpdateFixture;
use Tests\Support\StandaloneCore;

class ErrorViewsTest extends TestCase
{
    private CoreUpdateFixture $fixture;

    private string $previousLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CoreUpdateFixture();
        $this->fixture->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
        $this->fixture->write('app/config/app.php', <<<'PHP'
<?php
declare(strict_types=1);
return ['name' => '<Site>', 'debug' => false, 'base_path' => '', 'locales' => ['en'], 'default_locale' => 'en'];
PHP);
        $this->fixture->write('app/config/cache.php', <<<'PHP'
<?php
declare(strict_types=1);
return ['version' => '1'];
PHP);
        $this->fixture->write('app/routes.php', "<?php\ndeclare(strict_types=1);\n");
        $this->fixture->write('app/views/.keep', '');
        $this->fixture->write('app/lang/.keep', '');
        $this->fixture->write('storage/cache/.keep', '');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->fixture->path('errors.log'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousLog);
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('customTemplates')]
    public function it_renders_the_exact_status_view_with_safe_data(string $extension, string $template): void
    {
        $this->fixture->write("app/views/errors/404.{$extension}", $template);
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('<main>Custom 404 &lt;Site&gt; Route not found.</main>', $response->getContent());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public static function customTemplates(): array
    {

        return [
            'Blade' => ['arix.php', '<main>Custom {{ $status }} {{ $name }} {{ $message }}</main>'],
            'native' => ['php', '<?php declare(strict_types=1); ?><main>Custom <?= $status ?> <?= $this->escape($name) ?> <?= $this->escape($message) ?></main>'],
        ];
    }

    #[Test]
    public function it_customizes_unexpected_production_errors_without_exposing_the_exception(): void
    {
        $this->fixture->write('app/views/errors/500.arix.php', '<main>Custom {{ $status }} {{ $message }}</main>');
        $this->fixture->write('app/routes.php', '<?php declare(strict_types=1); throw new \RuntimeException("production secret");');
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('<main>Custom 500 Internal server error.</main>', $response->getContent());
        $this->assertStringNotContainsString('production secret', $response->getContent());
        $this->assertStringContainsString('production secret', file_get_contents($this->fixture->path('errors.log')));
    }

    #[Test]
    public function it_preserves_http_exception_headers_when_rendering_a_custom_view(): void
    {
        $this->fixture->write('app/views/errors/405.arix.php', '<main>{{ $status }} {{ $message }}</main>');
        $this->fixture->write('app/routes.php', <<<'PHP'
<?php
declare(strict_types=1);
throw new \Arix\Http\HttpException(\Arix\Constants\HttpStatusCode::METHOD_NOT_ALLOWED, '<script>unsafe</script>', ['Allow' => 'GET, HEAD', 'Cache-Control' => 'public']);
PHP);
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('<main>405 &lt;script&gt;unsafe&lt;/script&gt;</main>', $response->getContent());
        $this->assertSame('GET, HEAD', $response->headers->get('Allow'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[Test]
    public function it_supports_error_view_helpers_and_layouts_without_composer(): void
    {
        $this->fixture->write('app/views/errors/404.arix.php', "@extends('layouts.error')\n@section('content'){{ \$status }} {{ \$name }} {{ \$message }} @include('errors.details') @endsection");
        $this->fixture->write('app/views/layouts/error.arix.php', '<main lang="{{ config(\'app.locale\') }}">@yield(\'content\')</main>');
        $this->fixture->write('app/views/errors/details.arix.php', "{{ trans('missing') }} {{ url()->currentPath() }}");
        $this->fixture->write('app/lang/en.php', "<?php declare(strict_types=1); return ['missing' => 'Unavailable'];");
        $program = <<<'PHP'
require $argv[1];
if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
$response = \Arix\Application::create($argv[2])->kernel()->handle(\Arix\HttpFoundation\Request::create('/missing'));
echo $response->getContent();
PHP;
        $process = new Process([PHP_BINARY, '-r', $program, StandaloneCore::autoload($this->fixture->root), $this->fixture->root], $this->fixture->root, ['XDEBUG_MODE' => 'off']);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('<main lang="en">404 &lt;Site&gt; Route not found. Unavailable /missing', $process->getOutput());
    }

    #[Test]
    public function it_does_not_use_a_500_view_for_a_missing_status_template(): void
    {
        $this->fixture->write('app/views/errors/500.arix.php', 'Wrong status template');
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('<title>404 — ArixPHP</title>', $response->getContent());
        $this->assertStringNotContainsString('Wrong status template', $response->getContent());
    }

    #[Test]
    #[DataProvider('brokenTemplates')]
    public function it_falls_back_when_a_custom_template_cannot_be_rendered(string $extension, string $template): void
    {
        $this->fixture->write("app/views/errors/404.{$extension}", $template);
        $level = ob_get_level();
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame($level, ob_get_level());
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('<title>404 — ArixPHP</title>', $response->getContent());
        $this->assertStringContainsString('<pre>Route not found.</pre>', $response->getContent());
        $this->assertStringNotContainsString('partial secret', $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public static function brokenTemplates(): array
    {

        return [
            'native exception' => ['php', '<?php declare(strict_types=1); echo "partial secret"; throw new \RuntimeException("template secret");'],
            'native syntax' => ['php', '<?php declare(strict_types=1); syntax error'],
            'Blade syntax' => ['arix.php', 'partial secret @if( @endif'],
            'missing layout' => ['arix.php', "@extends('layouts.missing')\n@section('content')partial secret @endsection"],
            'missing include' => ['arix.php', "partial secret @include('errors.missing')"],
            'circular include' => ['arix.php', "partial secret @include('errors.404')"],
            'empty output' => ['arix.php', ''],
            'whitespace output' => ['php', '<?php declare(strict_types=1); echo "  ";'],
            'invalid encoding' => ['php', '<?php declare(strict_types=1); echo "invalid \\xff";'],
        ];
    }

    #[Test]
    public function it_rejects_conflicting_custom_template_extensions(): void
    {
        $this->fixture->write('app/views/errors/404.arix.php', 'Ambiguous Blade');
        $this->fixture->write('app/views/errors/404.php', '<?php declare(strict_types=1); echo "Ambiguous native";');
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('<title>404 — ArixPHP</title>', $response->getContent());
    }

    #[Test]
    #[DataProvider('linkedTemplates')]
    public function it_rejects_linked_error_templates(string $linked): void
    {
        $target = $this->fixture->write('external/404.arix.php', 'External secret');
        $this->fixture->write('app/views/errors/.keep', '');

        if ($linked === 'directory') {
            unlink($this->fixture->path('app/views/errors/.keep'));
            rmdir($this->fixture->path('app/views/errors'));
            symlink(dirname($target), $this->fixture->path('app/views/errors'));
        } else {
            symlink($target, $this->fixture->path('app/views/errors/404.arix.php'));
        }

        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('<title>404 — ArixPHP</title>', $response->getContent());
        $this->assertStringNotContainsString('External secret', $response->getContent());
    }

    public static function linkedTemplates(): array
    {

        return [['file'], ['directory']];
    }

    #[Test]
    public function it_falls_back_when_the_compilation_cache_is_unusable(): void
    {
        $this->fixture->write('app/views/errors/404.arix.php', 'Custom {{ $status }}');
        unlink($this->fixture->path('storage/cache/.keep'));
        rmdir($this->fixture->path('storage/cache'));
        $this->fixture->write('storage/cache', 'blocked');
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('<title>404 — ArixPHP</title>', $response->getContent());
    }

    #[Test]
    public function it_keeps_unexpected_debug_errors_as_plain_text_without_rendering_custom_views(): void
    {
        $this->fixture->write('app/config/app.php', "<?php declare(strict_types=1); return ['name' => 'Site', 'debug' => true, 'locales' => ['en'], 'default_locale' => 'en'];");
        $this->fixture->write('app/views/errors/500.php', '<?php declare(strict_types=1); file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . "rendered", "yes"); echo "Custom debug";');
        $this->fixture->write('app/routes.php', '<?php declare(strict_types=1); throw new \RuntimeException("debug secret");');
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('debug secret', $response->getContent());
        $this->assertFileDoesNotExist($this->fixture->path('app/views/errors/rendered'));
    }

    #[Test]
    public function it_customizes_expected_http_errors_even_in_debug_mode(): void
    {
        $this->fixture->write('app/config/app.php', "<?php declare(strict_types=1); return ['debug' => true, 'locales' => ['en'], 'default_locale' => 'en'];");
        $this->fixture->write('app/views/errors/404.arix.php', 'Custom {{ $status }} {{ $name }}');
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Custom 404 ArixPHP', $response->getContent());
    }

    #[Test]
    public function it_uses_the_managed_fallback_before_view_registration(): void
    {
        $this->fixture->write('app/views/errors/400.arix.php', 'Custom 400');
        $this->fixture->write('app/views/errors/500.arix.php', 'Custom 500');
        $application = Application::create($this->fixture->root);
        $response = $application->kernel()->handle(Request::create('/%2e%2e'));
        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('<title>400 — ArixPHP</title>', $response->getContent());

        $this->fixture->write('app/config/app.php', '<?php declare(strict_types=1); throw new \RuntimeException("config secret");');
        $response = $application->kernel()->handle(Request::create('/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('<title>500 — ArixPHP</title>', $response->getContent());

        $missingRoot = $this->fixture->path('missing');
        $response = new HttpRuntime($this->fixture->root, logger: static function (): void {})->handle(static fn () => Application::create($missingRoot), 'fpm-fcgi');
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('<title>500 — ArixPHP</title>', $response->getContent());
    }

    #[Test]
    public function it_suppresses_custom_head_bodies_and_isolates_consecutive_requests(): void
    {
        $template = $this->fixture->write('app/views/errors/404.arix.php', "@section('label')First @endsection\n@yield('label') {{ \$name }}");
        $kernel = Application::create($this->fixture->root)->kernel();
        $head = $kernel->handle(Request::create('/missing', 'HEAD'));
        $this->assertSame(404, $head->getStatusCode());
        $this->assertSame('', $head->getContent());
        $this->assertSame('text/html; charset=UTF-8', $head->headers->get('Content-Type'));
        $this->assertStringContainsString('First', $kernel->handle(Request::create('/one'))->getContent());

        file_put_contents($template, "@yield('label', 'Fresh') {{ \$name }}");
        $this->fixture->write('app/config/app.php', "<?php declare(strict_types=1); return ['name' => 'Second site', 'debug' => false, 'locales' => ['en'], 'default_locale' => 'en'];");
        $this->assertSame('Fresh Second site', $kernel->handle(Request::create('/two'))->getContent());

        $this->expectException(LogicException::class);
        ContainerContext::current();
    }

    #[Test]
    #[DataProvider('debugModes')]
    public function it_keeps_request_exception_details_out_of_custom_400_views(bool $debug): void
    {
        $this->fixture->write('app/config/app.php', "<?php declare(strict_types=1); return ['debug' => " . var_export($debug, true) . ", 'locales' => ['en'], 'default_locale' => 'en'];");
        $this->fixture->write('app/views/errors/400.arix.php', 'Custom {{ $status }} {{ $message }}');
        $this->fixture->write('app/routes.php', '<?php declare(strict_types=1); throw new \Arix\HttpFoundation\Exception\BadRequestException("request secret");');
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Custom 400 Bad request.', $response->getContent());
    }

    public static function debugModes(): array
    {

        return [[false], [true]];
    }

    #[Test]
    public function it_discards_flushed_partial_template_output_before_falling_back(): void
    {
        $this->fixture->write('app/views/errors/404.php', '<?php declare(strict_types=1); echo "partial secret"; ob_flush(); throw new \RuntimeException("render secret");');
        $level = ob_get_level();
        ob_start();

        try {
            $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));
            $output = ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertSame('', $output);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('<pre>Route not found.</pre>', $response->getContent());
    }

    #[Test]
    public function it_preserves_service_provided_configuration_when_composing_error_views(): void
    {
        $this->fixture->write('app/services.php', <<<'PHP'
<?php
declare(strict_types=1);
return static function (\Arix\Container\Container $container): void {
    $values = $container->get(\Arix\Config\Repository::class)->get();
    $values['app']['name'] = 'Service site';
    $values['custom']['label'] = 'Service customization';
    $container->instance(\Arix\Config\Repository::class, new \Arix\Config\Repository($values));
};
PHP);
        $this->fixture->write('app/views/errors/404.arix.php', "Custom {{ \$name }} {{ \\Arix\\Container\\ContainerContext::current()->get(\\Arix\\Config\\Repository::class)->get('custom.label') }}");
        $response = Application::create($this->fixture->root)->kernel()->handle(Request::create('/missing'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Custom Service site Service customization', $response->getContent());
    }
}
