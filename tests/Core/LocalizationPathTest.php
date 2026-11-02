<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Config\Loader;
use Arix\Localization\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

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
}
