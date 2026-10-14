<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use Arix\Views\BladeAdapter;
use Arix\Views\CompiledTemplateCache;
use Arix\Views\TemplateEngine;
use Arix\Views\TemplateResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TemplateEngineTest extends TestCase
{
    private string $views;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-engine-' . bin2hex(random_bytes(8));
        $this->views = $root . DIRECTORY_SEPARATOR . 'views';
        $this->cache = $root . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->views . DIRECTORY_SEPARATOR . 'layouts', 0755, true);
        mkdir($this->views . DIRECTORY_SEPARATOR . 'partials', 0755, true);
        mkdir($this->views . DIRECTORY_SEPARATOR . 'components', 0755, true);
        mkdir($this->cache, 0755, true);

        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'main.arix.php', "<html>@yield('content')</html>");
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'page.arix.php', "@extends('layouts.main')\n@section('content')<h1>{{ \$title }}</h1>{!! \$raw !!}@endsection");
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'greet.arix.php', '<p>Hello {{ $name }}!</p>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'withinclude.arix.php', "@include('partials.greet', ['name' => \$who])");
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'card.arix.php', '<article><h2>{{ $heading }}</h2><div>{!! $slot !!}</div></article>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'withcomponent.arix.php', "@component('components.card', ['heading' => \$title])<p>body</p>@endcomponent");
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'cond.arix.php', '@if($show)yes @else no @endif @foreach($items as $item){{$item}}; @endforeach');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'json.arix.php', '@json($value)');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'inlinesection.arix.php', '@section(\'t\', $v) @yield(\'t\')');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'yielddefault.arix.php', '@yield(\'missing\', \'<b>D</b>\')');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'cyclea.arix.php', "@include('cycleb')");
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'cycleb.arix.php', "@include('cyclea')");
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'boom.arix.php', 'before <?php throw new RuntimeException(\'boom\'); ?> after');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native.php', '<p>native</p>');
    }

    protected function tearDown(): void
    {
        chmod($this->cache, 0755);
        $this->remove(dirname($this->views));
        parent::tearDown();
    }

    #[Test]
    public function it_renders_layouts_sections_and_escapes(): void
    {
        $html = (new TemplateEngine($this->views, $this->cache))->render('page', ['title' => '<Hi>', 'raw' => '<b>raw</b>']);

        $this->assertSame('<html><h1>&lt;Hi&gt;</h1><b>raw</b></html>', $html);
    }

    #[Test]
    public function it_renders_includes_components_conditionals_and_json(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);

        $this->assertSame('<p>Hello Ada!</p>', $engine->render('withinclude', ['who' => 'Ada']));
        $this->assertSame('<article><h2>Card</h2><div><p>body</p></div></article>', $engine->render('withcomponent', ['title' => 'Card']));
        $this->assertSame('yes a; b; ', $engine->render('cond', ['show' => true, 'items' => ['a', 'b']]));
        $this->assertSame('no ', $engine->render('cond', ['show' => false, 'items' => []]));
        $this->assertSame('{"a":"\u003Cb\u003E"}', $engine->render('json', ['value' => ['a' => '<b>']]));
        $this->assertSame('&lt;i&gt;X&lt;/i&gt;', $engine->render('inlinesection', ['v' => '<i>X</i>']));
        $this->assertSame('&lt;b&gt;D&lt;/b&gt;', $engine->render('yielddefault'));
    }

    #[Test]
    public function it_reuses_compiled_files_and_recompiles_on_content_change(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);
        $first = $engine->render('page', ['title' => 'T', 'raw' => 'R']);
        $mtimes = [];

        foreach (array_diff(scandir($this->cache), ['.', '..']) as $file) {
            $mtimes[$file] = filemtime($this->cache . DIRECTORY_SEPARATOR . $file);
        }

        $this->assertSame($first, $engine->render('page', ['title' => 'T', 'raw' => 'R']));

        foreach ($mtimes as $file => $mtime) {
            $this->assertSame($mtime, filemtime($this->cache . DIRECTORY_SEPARATOR . $file));
        }

        $source = $this->views . DIRECTORY_SEPARATOR . 'partials' . DIRECTORY_SEPARATOR . 'greet.arix.php';
        $mtime = filemtime($source);
        file_put_contents($source, '<p>Hi {{ $name }}!</p>');
        touch($source, $mtime);

        $this->assertSame('<p>Hi Ada!</p>', $engine->render('withinclude', ['who' => 'Ada']));
    }

    #[Test]
    public function it_rejects_cycles_and_missing_templates(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);
        $rejected = 0;

        foreach (['cyclea', 'missing'] as $name) {
            try {
                $engine->render($name);
            } catch (RuntimeException) {
                $rejected++;
            }
        }

        $this->assertSame(2, $rejected);
        $this->assertSame('<p>native</p>', $engine->render('native'));
    }

    #[Test]
    public function it_fails_closed_on_unwritable_cache_without_partial_output(): void
    {
        chmod($this->cache, 0555);

        if (is_writable($this->cache)) {
            $this->markTestSkipped('This filesystem/user does not enforce POSIX write permissions.');
        }

        $engine = new TemplateEngine($this->views, $this->cache);
        $level = ob_get_level();
        ob_start();

        try {
            $engine->render('page', ['title' => 'T', 'raw' => 'R']);
            $this->fail('Unwritable cache must fail closed.');
        } catch (RuntimeException) {
        }

        $this->assertSame('', ob_get_clean());
        $this->assertSame($level, ob_get_level());
    }

    #[Test]
    public function it_restores_buffers_and_state_after_template_errors(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);
        $level = ob_get_level();

        try {
            $engine->render('boom');
            $this->fail('Template errors must surface.');
        } catch (RuntimeException) {
        }

        $this->assertSame($level, ob_get_level());
        $this->assertSame('<html><h1>T</h1>R</html>', $engine->render('page', ['title' => 'T', 'raw' => 'R']));
    }

    #[Test]
    public function it_rejects_reserved_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TemplateEngine($this->views, $this->cache))->render('page', ['this' => 'x']);
    }

    #[Test]
    public function it_never_evaluates_template_strings(): void
    {
        $adapter = new BladeAdapter(new TemplateResolver($this->views), new CompiledTemplateCache($this->cache, '1'));

        $this->expectException(RuntimeException::class);
        $adapter->runString('{{ 1 + 1 }}');
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
