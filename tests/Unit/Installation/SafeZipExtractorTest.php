<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\SafeZipExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\NewApplicationFixture;
use ZipArchive;

class SafeZipExtractorTest extends TestCase
{
    private NewApplicationFixture $fixture;

    #[Test]
    #[DataProvider('requiredPublicFiles')]
    public function it_rejects_incomplete_public_resource_skeletons(string $path): void
    {
        $entries = NewApplicationFixture::skeleton();

        unset($entries["app-sha/{$path}"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('missing required file');

        new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
    }

    public static function requiredPublicFiles(): array
    {

        return array_map(static fn (string $path): array => [$path], [
            'fonts/.htaccess', 'media/.htaccess', 'media/arix-icon.webp',
            'media/arix-emblem.webp', 'media/arix-combinationmark.webp',
            'scripts/arix/index.js', 'scripts/arix/dev-reload.js',
            'styles/arix/base.css', 'styles/arix/components.css',
            'styles/arix/tokens.css', 'styles/arix/utilities.css',
        ]);
    }

    #[Test]
    public function it_rejects_the_legacy_assets_tree(): void
    {
        $entries = NewApplicationFixture::skeleton();
        $entries['app-sha/assets/logo.webp'] = 'legacy';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('legacy assets');

        new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
    }

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
        mkdir($this->fixture->path('output'), 0700);
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_preserves_hidden_files_and_never_executes_downloaded_php(): void
    {
        new SafeZipExtractor()->extract($this->fixture->archive(), $this->fixture->path('output'));

        $this->assertSame('RewriteEngine On', file_get_contents($this->fixture->path('output/.htaccess')));
        $this->assertSame('/storage/cache', file_get_contents($this->fixture->path('output/.gitignore')));
        $this->assertSame('<?php // downloaded core', file_get_contents($this->fixture->path('output/core/autoload.php')));
        $this->assertFileExists($this->fixture->path('output/index.php'));
        $this->assertDirectoryDoesNotExist($this->fixture->path('output/app-sha'));
    }

    #[Test]
    #[DataProvider('unsafeEntries')]
    public function it_rejects_unsafe_entry_paths_before_extraction(string $entry): void
    {
        $entries = NewApplicationFixture::skeleton();
        $entries[$entry] = 'unsafe';

        try {
            new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
            $this->fail('Unsafe entry accepted.');
        } catch (RuntimeException) {
            $this->assertSame(['.', '..'], scandir($this->fixture->path('output')));
            $this->assertFileDoesNotExist($this->fixture->path('escape'));
        }
    }

    public static function unsafeEntries(): array
    {
        return array_map(static fn (string $path): array => [$path], ['../escape', 'app-sha/../escape', '/absolute', 'C:/absolute', '\\server\\share', 'app-sha/a\\b', 'app-sha//file', 'app-sha/./file', "app-sha/control\nfile", 'app-sha/file:stream', 'app-sha/file.', 'app-sha/CON.txt', 'other-root/file']);
    }

    #[Test]
    public function it_rejects_case_collisions(): void
    {
        $entries = NewApplicationFixture::skeleton();
        $entries['app-sha/core/A.php'] = 'one';
        $entries['app-sha/core/a.php'] = 'two';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('collision');

        new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
    }

    #[Test]
    public function it_rejects_duplicate_entries(): void
    {
        $entries = NewApplicationFixture::skeleton();
        $entries['app-sha/A.php'] = 'one';
        $entries['app-sha/B.php'] = 'two';
        $path = $this->fixture->archive($entries);

        file_put_contents($path, str_replace('app-sha/B.php', 'app-sha/A.php', file_get_contents($path)));

        $this->expectException(RuntimeException::class);

        new SafeZipExtractor()->extract($path, $this->fixture->path('output'));
    }

    #[Test]
    public function it_rejects_file_and_implicit_directory_conflicts(): void
    {
        $entries = NewApplicationFixture::skeleton();
        $entries['app-sha/core'] = 'not a directory';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('collision');

        new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
    }

    #[Test]
    public function it_rejects_symlink_entries(): void
    {
        $path = $this->fixture->archive();

        $zip = new ZipArchive();
        $zip->open($path);
        $zip->addFromString('app-sha/link', '../escape');
        $zip->setExternalAttributesName('app-sha/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('link');

        new SafeZipExtractor()->extract($path, $this->fixture->path('output'));
    }

    #[Test]
    public function it_rejects_corrupt_archives(): void
    {
        $this->expectException(RuntimeException::class);

        new SafeZipExtractor()->extract($this->fixture->write('broken.zip', 'not zip'), $this->fixture->path('output'));
    }

    #[Test]
    public function it_rejects_unsupported_contracts(): void
    {
        $entries = NewApplicationFixture::skeleton();
        $entries['app-sha/.arix.json'] = '{"format":2,"core_contract":"different"}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('contract');

        new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
    }

    #[Test]
    #[DataProvider('nativeAutoloadFiles')]
    public function it_rejects_skeletons_without_native_autoload_declarations(string $path): void
    {
        $entries = NewApplicationFixture::skeleton();

        unset($entries["app-sha/{$path}"]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('missing required file');

        new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
    }

    public static function nativeAutoloadFiles(): array
    {

        return array_map(static fn (string $path): array => [$path], ['core/Autoload/AutoloadConfiguration.php', 'core/Autoload/ClassLoader.php']);
    }

    #[Test]
    public function it_rejects_missing_required_files(): void
    {
        $entries = NewApplicationFixture::skeleton();

        unset($entries['app-sha/core/autoload.php']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('core');

        new SafeZipExtractor()->extract($this->fixture->archive($entries), $this->fixture->path('output'));
    }

    #[Test]
    public function it_limits_uncompressed_bytes_before_writing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('size limit');

        new SafeZipExtractor(maxBytes: 10)->extract($this->fixture->archive(), $this->fixture->path('output'));
    }

    #[Test]
    public function it_limits_entry_count(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('entry limit');

        new SafeZipExtractor(maxEntries: 2)->extract($this->fixture->archive(), $this->fixture->path('output'));
    }

    #[Test]
    public function it_rejects_nonempty_extraction_destinations(): void
    {
        $this->fixture->write('output/keep', 'keep');

        $this->expectException(RuntimeException::class);

        new SafeZipExtractor()->extract($this->fixture->archive(), $this->fixture->path('output'));
    }
}
