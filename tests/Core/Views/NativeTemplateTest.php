<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use Arix\Views\TemplateEngine;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NativeTemplateTest extends TestCase
{
    private string $views;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-native-' . bin2hex(random_bytes(8));
        $this->views = $root . DIRECTORY_SEPARATOR . 'views';
        $this->cache = $root . DIRECTORY_SEPARATOR . 'cache';
        mkdir($this->views . DIRECTORY_SEPARATOR . 'native', 0755, true);
        mkdir($this->cache, 0755, true);

        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'hello.php', '<p>Hello <?= $this->escape($name) ?></p>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'layout.php', '<html><?= $this->yieldSection(\'content\', \'Default\') ?></html>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'page.php', '<?php $this->extend(\'native.layout\'); $this->startSection(\'content\'); ?><h1><?= $this->escape($title) ?></h1><?php $this->endSection(); ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'fileinfo.php', '<?= __FILE__ ?>|<?= basename(__DIR__) ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'card.php', '<article><h2><?= $this->escape($heading) ?></h2><div><?= $slot ?></div></article>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'withcomponent.php', '<?php $this->startComponent(\'native.card\', [\'heading\' => $title]); ?><p>body</p><?= $this->endComponent() ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'toblade.php', '<?php $this->extend(\'blayout\'); $this->startSection(\'content\'); ?>native-body<?php $this->endSection(); ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'cyclea.php', '<?php $this->extend(\'native.cycleb\'); ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'cycleb.php', '<?php $this->extend(\'native.cyclea\'); ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'unclosed.php', '<?php $this->startSection(\'content\'); ?>oops');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'twice.php', '<?php $this->extend(\'native.layout\'); $this->extend(\'native.layout\'); ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'bpart.arix.php', '<b>{{ $v }}</b>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'withblade.php', '<?= $this->partial(\'bpart\', [\'v\' => $v]) ?>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'blayout.arix.php', '<html>@yield(\'content\')</html>');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'bwithnative.arix.php', '@include(\'native.hello\', [\'name\' => $who])');
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'bextendsnative.arix.php', '@extends(\'native.layout\') @section(\'content\')blade-body @endsection');
    }

    protected function tearDown(): void
    {
        $this->remove(dirname($this->views));
        parent::tearDown();
    }

    #[Test]
    public function it_renders_native_templates_preserving_file_semantics(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);

        $this->assertSame('<p>Hello Ada</p>', $engine->render('native.hello', ['name' => 'Ada']));
        $expected = $this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'fileinfo.php' . '|native';

        $this->assertSame($expected, $engine->render('native.fileinfo'));
    }

    #[Test]
    public function it_composes_native_layouts_sections_and_components(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);

        $this->assertSame('<html><h1>T</h1></html>', $engine->render('native.page', ['title' => 'T']));
        $this->assertSame('<article><h2>C</h2><div><p>body</p></div></article>', $engine->render('native.withcomponent', ['title' => 'C']));
    }

    #[Test]
    public function it_composes_mixed_native_and_blade_templates(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);

        $this->assertSame('<b>V</b>', $engine->render('native.withblade', ['v' => 'V']));
        $this->assertSame('<p>Hello Ada</p>', $engine->render('bwithnative', ['who' => 'Ada']));
        $this->assertSame('<html>native-body</html>', $engine->render('native.toblade'));
        $this->assertSame('<html>blade-body </html>', $engine->render('bextendsnative'));
    }

    #[Test]
    public function it_rejects_native_cycles_unclosed_captures_and_double_layouts(): void
    {
        $engine = new TemplateEngine($this->views, $this->cache);
        $rejected = 0;

        foreach (['native.cyclea', 'native.unclosed', 'native.twice'] as $name) {
            try {
                $engine->render($name);
            } catch (RuntimeException) {
                $rejected++;
            }
        }

        $this->assertSame(3, $rejected);
        $this->assertSame('<p>Hello Ada</p>', $engine->render('native.hello', ['name' => 'Ada']));
    }

    #[Test]
    public function it_rejects_reserved_data_in_native_partials(): void
    {
        file_put_contents($this->views . DIRECTORY_SEPARATOR . 'native' . DIRECTORY_SEPARATOR . 'badpartial.php', '<?= $this->partial(\'native.hello\', [\'this\' => 1]) ?>');

        $this->expectException(InvalidArgumentException::class);
        (new TemplateEngine($this->views, $this->cache))->render('native.badpartial', ['name' => 'Ada']);
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
