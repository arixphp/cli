<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

class ApplicationViewsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-appviews-' . bin2hex(random_bytes(8));

        mkdir($this->directory, 0755, true);

        $reference = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'app';
        $views = $reference . DIRECTORY_SEPARATOR . 'views';

        $this->assertDirectoryExists($views);
        $this->assertDirectoryExists($reference . DIRECTORY_SEPARATOR . 'lang');
        $this->copy($views, $this->directory . DIRECTORY_SEPARATOR . 'views');
        $this->copy($reference . DIRECTORY_SEPARATOR . 'lang', $this->directory . DIRECTORY_SEPARATOR . 'lang');

        mkdir($this->directory . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache', 0755, true);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'styles');
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'scripts');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'styles' . DIRECTORY_SEPARATOR . 'app.css', 'abc');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'app.js', 'abc');
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config', 0755, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', "<?php return ['name' => 'ArixPHP', 'locale' => 'en'];");
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_renders_the_reference_application_views_without_composer(): void
    {
        $autoload = TestApplication::shared()->path('core/autoload.php');
        $program = 'require ' . var_export($autoload, true) . '; ' . <<<'PHP'
            if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
            PHP;
        $program .= '$root = ' . var_export($this->directory, true) . '; ' . <<<'PHP'
            $render = static function (string $path, string $view, string $titleKey, string $descriptionKey) use ($root): string {
                $request = Arix\HttpFoundation\Request::create($path);
                $request->attributes->set('_arix_path', $path);
                $container = new Arix\Container\Container;
                Arix\Config\ConfigServices::register($container, $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'config');
                $context = Arix\Http\RequestUrlContext::fromRequest($request);
                Arix\Assets\AssetServices::register($container, $root, Arix\Routing\UrlGenerator::fromRequest($request, $context), Arix\Routing\UrlGenerator::fromRequest($request, $context, assets: true));
                Arix\Views\ViewServices::register($container, $root, [
                    'app' => ['name' => 'ArixPHP', 'base_path' => '', 'locales' => ['es', 'en'], 'default_locale' => 'en'],
                    'cache' => ['version' => '1'],
                    'views' => $root . '/views',
                    'lang' => $root . '/lang',
                ]);
                return Arix\Container\ContainerContext::run($container, static function () use ($container, $view, $titleKey, $descriptionKey): string {
                    $engine = $container->get(Arix\Views\TemplateEngine::class);
                    return $engine->render($view, ['title' => trans($titleKey), 'description' => trans($descriptionKey)]);
                });
            };
            echo $render('/', 'index', 'home.title', 'home.description');
            echo "\n---PAGE---\n";
            echo $render('/about', 'about', 'about.title', 'about.description');
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        [$index, $about] = explode("\n---PAGE---\n", $output);

        $this->assertStringContainsString('<html lang="en">', $index);
        $this->assertStringContainsString('<title>Simple sites. Native PHP. · ArixPHP</title>', $index);
        $this->assertStringContainsString('<h1>Simple sites. Native PHP.</h1>', $index);
        $this->assertStringContainsString('<p class="lead">Fast multilingual pages, without Composer or frontend builds.</p>', $index);
        $this->assertStringContainsString('<article class="card">', $index);
        $this->assertStringContainsString('<h2>Ready for your hosting</h2>', $index);
        $this->assertStringContainsString('Explicit routes and controllers, layouts, components and caching.', $index);
        $this->assertStringContainsString('<nav aria-label="Main navigation">', $index);
        $this->assertStringContainsString('aria-current="page" >Home</a>', $index);
        $this->assertStringContainsString('Server-rendered HTML. JavaScript only when you need it.', $index);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/">', $index);
        $this->assertStringContainsString('/styles/app.css?v=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $index);
        $this->assertStringContainsString('/scripts/app.js?v=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $index);

        $this->assertStringContainsString('<title>About ArixPHP · ArixPHP</title>', $about);
        $this->assertStringContainsString('<h1>About ArixPHP</h1>', $about);
        $this->assertStringContainsString('A small framework for content-driven websites.', $about);
        $this->assertStringContainsString('aria-current="page" >About</a>', $about);
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/about">', $about);
    }

    private function copy(string $from, string $to): void
    {
        if (is_link($from)) {
            $this->fail("Reference views must not contain symlinks: {$from}");
        }

        if (is_dir($from)) {
            mkdir($to, 0755, true);

            foreach (array_diff(scandir($from), ['.', '..']) as $entry) {
                $this->copy($from . DIRECTORY_SEPARATOR . $entry, $to . DIRECTORY_SEPARATOR . $entry);
            }
        } else {
            copy($from, $to);
        }
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
