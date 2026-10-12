<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use Arix\Config\ConfigServices;
use Arix\Config\Repository;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\HttpFoundation\Request;
use Arix\Localization\Translator;
use Arix\Routing\UrlGenerator;
use Arix\Views\TemplateEngine;
use Arix\Views\ViewServices;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ViewHelpersTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-helpers-' . bin2hex(random_bytes(8));
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'views', 0755, true);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'lang', 0755, true);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'cache', 0755, true);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config', 0755, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', "<?php return ['name' => 'Test Site', 'locale' => 'en'];");
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en.php', "<?php declare(strict_types=1); return ['home' => ['title' => 'Welcome']];");
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'es.php', "<?php declare(strict_types=1); return ['home' => ['title' => 'Bienvenido']];");
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_reads_dotted_configuration_and_rejects_literal_dots(): void
    {
        $repository = new Repository(['app' => ['name' => 'Site', 'nested' => ['value' => 1]]]);

        $this->assertSame('Site', $repository->get('app.name'));
        $this->assertSame(1, $repository->get('app.nested.value'));
        $this->assertSame('fallback', $repository->get('app.missing', 'fallback'));
        $this->assertSame(['app' => ['name' => 'Site', 'nested' => ['value' => 1]]], $repository->get());

        $this->expectException(InvalidArgumentException::class);
        new Repository(['app.name' => 'bad']);
    }

    #[Test]
    public function it_translates_with_default_locale_fallback(): void
    {
        $translator = new Translator($this->directory . DIRECTORY_SEPARATOR . 'lang', ['en', 'es'], 'en', 'es');

        $this->assertSame('Bienvenido', $translator->get('home.title'));
        $this->assertSame('missing.key', $translator->get('missing.key'));
    }

    #[Test]
    public function it_replaces_translation_placeholders(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en.php', "<?php declare(strict_types=1); return ['hello' => 'Hello :name'];");
        $translator = new Translator($this->directory . DIRECTORY_SEPARATOR . 'lang', ['en'], 'en', 'en');

        $this->assertSame('Hello Ada', $translator->get('hello', ['name' => 'Ada']));
    }

    #[Test]
    #[DataProvider('invalidTranslators')]
    public function it_rejects_invalid_translator_configuration(array $locales, string $default, string $locale): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Translator($this->directory . DIRECTORY_SEPARATOR . 'lang', $locales, $default, $locale);
    }

    public static function invalidTranslators(): array
    {
        return [
            [[], 'en', 'en'],
            [['en'], 'es', 'en'],
            [['en'], 'en', 'es'],
            [['bad locale'], 'bad locale', 'bad locale'],
        ];
    }

    #[Test]
    public function it_builds_urls_assets_and_current_paths_with_base_path(): void
    {
        $urls = new UrlGenerator('/site', '/about');

        $this->assertSame('/site/about', $urls->to('/about'));
        $this->assertSame('/site/about', $urls->current());
        $this->assertSame('/about', $urls->currentPath());
        $this->assertSame('/site/styles/app.css', $urls->asset('styles/app.css'));
    }

    #[Test]
    #[DataProvider('invalidPaths')]
    public function it_rejects_unsafe_url_and_asset_paths(string $path): void
    {
        $urls = new UrlGenerator('', '/');

        try {
            $urls->to($path);
            $this->fail("Path {$path} must be rejected.");
        } catch (InvalidArgumentException) {
        }

        try {
            $urls->asset($path);
            $this->fail("Asset {$path} must be rejected.");
        } catch (InvalidArgumentException) {
        }

        $this->assertTrue(true);
    }

    public static function invalidPaths(): array
    {
        return [
            ['https://example.com/x'],
            ['/about?x=1'],
            ['/about#top'],
            ['/../secret'],
            ['/a/../b'],
            ["/a\x00b"],
            ['/a\\b'],
            ['/a//b'],
            [''],
        ];
    }

    #[Test]
    public function it_does_not_replace_the_bootstrapped_configuration(): void
    {
        $container = new Container();
        $repository = new Repository(['custom' => ['enabled' => true]]);
        $container->instance(Repository::class, $repository);
        ViewServices::register($container, $this->directory, Request::create('/'), [
            'app' => ['locales' => ['en'], 'default_locale' => 'en'],
            'cache' => [],
            'views' => $this->directory . DIRECTORY_SEPARATOR . 'views',
            'lang' => $this->directory . DIRECTORY_SEPARATOR . 'lang',
        ]);

        $this->assertSame($repository, $container->get(Repository::class));
        $this->assertTrue($container->get(Repository::class)->get('custom.enabled'));
    }

    #[Test]
    public function it_registers_request_services_and_exposes_app_locale(): void
    {
        $container = $this->container('/about');

        $this->assertSame('Test Site', $container->get(Repository::class)->get('app.name'));
        $this->assertSame('en', $container->get(Repository::class)->get('app.locale'));
        $this->assertSame('Welcome', $container->get(Translator::class)->get('home.title'));
        $this->assertSame('/site/about', $container->get(UrlGenerator::class)->current());
    }

    #[Test]
    public function it_isolates_requests_and_requires_an_active_container(): void
    {
        $first = $this->container('/one');
        $second = $this->container('/two');

        $this->assertNotSame($first->get(UrlGenerator::class), $second->get(UrlGenerator::class));
        $this->assertSame('/site/one', $first->get(UrlGenerator::class)->current());
        $this->assertSame('/site/two', $second->get(UrlGenerator::class)->current());

        $this->expectException(LogicException::class);
        ContainerContext::current();
    }

    #[Test]
    public function it_keys_compiled_templates_by_integration_and_deployment_versions(): void
    {
        $container = $this->container('/');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'probe.arix.php', 'probe');
        $container->get(TemplateEngine::class)->render('probe');
        $source = realpath($this->directory . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'probe.arix.php');
        $code = file_get_contents($source);
        $expected = $this->directory . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . hash('sha256', TemplateEngine::VERSION . "\0" . '1' . "\0" . $source . "\0" . $code) . '.php';

        $this->assertFileExists($expected);
    }

    #[Test]
    public function it_rejects_misconfigured_view_services(): void
    {
        $request = Request::create('/');

        foreach ([
            [],
            ['app' => [], 'cache' => [], 'views' => '', 'lang' => ''],
            ['app' => ['locales' => ['en'], 'default_locale' => 'en', 'base_path' => ['/site']], 'cache' => ['path' => 'x', 'version' => '1'], 'views' => $this->directory . DIRECTORY_SEPARATOR . 'views', 'lang' => $this->directory . DIRECTORY_SEPARATOR . 'lang'],
            ['app' => ['locales' => ['en'], 'default_locale' => 'en'], 'cache' => ['path' => 'x', 'version' => '1'], 'views' => $this->directory . DIRECTORY_SEPARATOR . 'missing', 'lang' => $this->directory . DIRECTORY_SEPARATOR . 'lang'],
        ] as $configuration) {
            try {
                ViewServices::register(new Container(), $this->directory, $request, $configuration);
                $this->fail('Misconfigured view services must be rejected.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->assertTrue(true);
    }

    #[Test]
    public function it_exposes_helpers_without_composer(): void
    {
        file_put_contents($this->views() . 'helper.arix.php', "{{ trans('home.title') }}|{{ config('app.name') }}|{{ url('/about') }}|{{ asset('styles/app.css') }}|{{ url()->current() }}|{{ url()->currentPath() }}");
        $autoload = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; ' . <<<'PHP'
            if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
            PHP;
        $program .= '$root = ' . var_export($this->directory, true) . '; ' . <<<'PHP'
            $request = Arix\HttpFoundation\Request::create('/site/about');
            $request->attributes->set('_arix_path', '/about');
            $container = new Arix\Container\Container;
            Arix\Config\ConfigServices::register($container, $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'config');
            Arix\Views\ViewServices::register($container, $root, $request, [
                'app' => ['name' => 'Test Site', 'base_path' => '/site', 'locales' => ['en'], 'default_locale' => 'en'],
                'cache' => ['path' => $root . '/cache', 'version' => '1'],
                'views' => $root . '/views',
                'lang' => $root . '/lang',
            ]);
            echo Arix\Container\ContainerContext::run($container, static function () use ($container): string {
                $view = view('helper');
                if (! $view instanceof Arix\Views\Contracts\View) { exit(3); }
                if (trans('missing.key') !== 'missing.key') { exit(4); }
                if (config('app.missing', 'dflt') !== 'dflt') { exit(5); }
                if (! (url() instanceof Arix\Routing\UrlGenerator)) { exit(6); }
                $engine = $container->get(Arix\Views\TemplateEngine::class);
                return $engine->render($view->name(), $view->data());
            });
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('Welcome|Test Site|/site/about|/site/styles/app.css|/site/about|/about', $output);
    }

    private function views(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR;
    }

    private function container(string $path): Container
    {
        $request = Request::create('/site' . $path);
        $request->attributes->set('_arix_path', $path);
        $container = new Container();
        ConfigServices::register($container, $this->directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config');
        ViewServices::register($container, $this->directory, $request, [
            'app' => ['name' => 'Test Site', 'base_path' => '/site', 'locales' => ['en', 'es'], 'default_locale' => 'en'],
            'cache' => ['path' => $this->directory . DIRECTORY_SEPARATOR . 'cache', 'version' => '1'],
            'views' => $this->directory . DIRECTORY_SEPARATOR . 'views',
            'lang' => $this->directory . DIRECTORY_SEPARATOR . 'lang',
        ]);

        return $container;
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
