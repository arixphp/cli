<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Config\Loader;
use Arix\HttpFoundation\Request;
use Arix\Localization\Translator;
use Arix\Views\ViewServicesConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Tests\Support\NativeLink;

class LocalizationPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-resources-' . bin2hex(random_bytes(8));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'lang', 0755, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
        parent::tearDown();
    }

    #[Test]
    #[DataProvider('resourceLinks')]
    public function it_rejects_linked_catalogues_and_configuration_without_executing_php(string $consumer, bool $broken, bool $directory, bool $trailing = false): void
    {
        $marker = $this->root . DIRECTORY_SEPARATOR . 'executed';
        $payload = $this->root . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . ($directory ? 'es.php' : 'en.php');
        file_put_contents($payload, '<?php declare(strict_types=1); file_put_contents(' . var_export($marker, true) . ', "yes"); return ["hello" => "unsafe"];');
        $target = $broken ? $this->root . DIRECTORY_SEPARATOR . 'missing' : ($directory ? dirname($payload) : $payload);
        $link = $this->root . DIRECTORY_SEPARATOR . ($directory ? 'linked' : 'lang' . DIRECTORY_SEPARATOR . 'es.php');
        NativeLink::create($target, $link, $directory);
        $path = $directory ? $link : dirname($link);
        $path .= $trailing ? DIRECTORY_SEPARATOR : '';

        try {
            $this->expectException(RuntimeException::class);

            if ($consumer === 'config') {
                (new Loader())->load($path);
            } else {
                (new Translator($path, ['es'], 'es', 'es'))->get('hello');
            }
        } finally {
            $this->assertFileDoesNotExist($marker);
        }
    }

    public static function resourceLinks(): array
    {

        return [
            ['translator', false, false], ['translator', true, false],
            ['translator', false, true], ['translator', true, true],
            ['config', false, true], ['config', true, true],
            ['translator', false, true, true], ['config', false, true, true],
        ];
    }

    #[Test]
    #[DataProvider('optionalDirectoryConsumers')]
    public function it_rejects_broken_ancestors_of_optional_resource_directories(string $consumer): void
    {
        $link = $this->root . DIRECTORY_SEPARATOR . 'broken';
        NativeLink::create($this->root . DIRECTORY_SEPARATOR . 'missing', $link, true);
        $directory = $link . DIRECTORY_SEPARATOR . 'optional';
        $this->expectException(RuntimeException::class);

        if ($consumer === 'config') {
            (new Loader())->load($directory);
        } else {
            (new Translator($directory, ['en'], 'en', 'en'))->get('hello');
        }
    }

    #[Test]
    #[DataProvider('optionalDirectoryConsumers')]
    public function it_fails_closed_for_unsearchable_optional_resource_ancestors(string $consumer): void
    {
        $parent = $this->root . DIRECTORY_SEPARATOR . 'lang';
        chmod($parent, 0000);

        try {

            if (is_readable($parent) || is_executable($parent)) {
                $this->markTestSkipped('Current user bypasses directory permission restrictions.');
            }

            $this->expectException(RuntimeException::class);

            if ($consumer === 'config') {
                (new Loader())->load($parent . DIRECTORY_SEPARATOR . 'missing');
            } else {
                (new Translator($parent . DIRECTORY_SEPARATOR . 'missing', ['en'], 'en', 'en'))->get('hello');
            }
        } finally {
            chmod($parent, 0755);
        }
    }

    public static function optionalDirectoryConsumers(): array
    {

        return [['config'], ['translator']];
    }

    #[Test]
    public function it_preserves_missing_catalogue_fallback(): void
    {
        $this->assertSame('hello', (new Translator($this->root . DIRECTORY_SEPARATOR . 'lang', ['es'], 'es', 'es'))->get('hello'));
    }

    #[Test]
    public function it_rejects_same_root_linked_configuration_files_before_inclusion(): void
    {
        $marker = $this->root . DIRECTORY_SEPARATOR . 'executed';
        $target = $this->root . DIRECTORY_SEPARATOR . 'payload.php';
        file_put_contents($target, '<?php declare(strict_types=1); file_put_contents(' . var_export($marker, true) . ', "yes"); return [];');
        symlink($target, $this->root . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'custom.php');

        try {
            $this->expectException(RuntimeException::class);
            (new Loader())->load($this->root . DIRECTORY_SEPARATOR . 'lang');
        } finally {
            $this->assertFileDoesNotExist($marker);
        }
    }

    #[Test]
    public function it_does_not_erase_linked_view_roots_during_service_configuration(): void
    {
        $link = $this->root . DIRECTORY_SEPARATOR . 'views';
        symlink($this->root . DIRECTORY_SEPARATOR . 'lang', $link);
        $this->expectException(RuntimeException::class);
        ViewServicesConfig::resolve($this->root, Request::create('/'), [
            'app' => ['locales' => ['en'], 'default_locale' => 'en'],
            'cache' => ['path' => $this->root . DIRECTORY_SEPARATOR . 'cache', 'version' => '1'],
            'views' => $link,
            'lang' => $this->root . DIRECTORY_SEPARATOR . 'lang',
        ]);
    }
}
