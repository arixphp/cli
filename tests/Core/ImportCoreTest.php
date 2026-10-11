<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Source;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ImportCoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR.'arix-import-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    #[Test]
    public function it_import_relocates_sources_preserves_licenses_and_is_repeatable(): void
    {
        $vendor = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor';
        $core = $this->directory.DIRECTORY_SEPARATOR.'core';
        $importer = new Source;
        $first = $importer->import($vendor, $core);

        $this->assertFileExists($core.DIRECTORY_SEPARATOR.'HttpFoundation'.DIRECTORY_SEPARATOR.'LICENSE');
        $this->assertDirectoryDoesNotExist($core.DIRECTORY_SEPARATOR.'Dependencies');
        $this->assertSame(['symfony/http-foundation'], array_keys($first['packages']));
        $this->assertSame(file_get_contents($vendor.DIRECTORY_SEPARATOR.'symfony'.DIRECTORY_SEPARATOR.'http-foundation'.DIRECTORY_SEPARATOR.'LICENSE'), file_get_contents($core.DIRECTORY_SEPARATOR.'HttpFoundation'.DIRECTORY_SEPARATOR.'LICENSE'));

        $source = file_get_contents($core.DIRECTORY_SEPARATOR.'HttpFoundation'.DIRECTORY_SEPARATOR.'Request.php');

        $this->assertStringContainsString('namespace Arix\\HttpFoundation;', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\HttpFoundation', $source);

        file_put_contents($core.DIRECTORY_SEPARATOR.'Kernel.php', '<?php // application-owned integration');

        $this->assertSame($first, $importer->import($vendor, $core));
        $this->assertSame('<?php // application-owned integration', file_get_contents($core.DIRECTORY_SEPARATOR.'Kernel.php'));
        $this->assertSame($first, json_decode(file_get_contents($core.DIRECTORY_SEPARATOR.'imports.json'), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_missing_sources_do_not_change_existing_destination(): void
    {
        mkdir($this->directory.DIRECTORY_SEPARATOR.'core');
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'keep.txt', 'keep');

        try {
            (new Source)->import($this->directory.DIRECTORY_SEPARATOR.'missing', $this->directory.DIRECTORY_SEPARATOR.'core');
            $this->fail('Missing vendor must fail.');
        } catch (RuntimeException) {
            $this->assertSame('keep', file_get_contents($this->directory.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'keep.txt'));
            $this->assertFileDoesNotExist($this->directory.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'imports.json');
        }
    }

    #[Test]
    public function it_import_copies_only_php_files_and_explicit_license_exception(): void
    {
        $vendor = $this->directory.DIRECTORY_SEPARATOR.'vendor';
        $core = $this->directory.DIRECTORY_SEPARATOR.'core';
        $packages = [
            'symfony/http-foundation' => 'HttpFoundation',
        ];
        $excluded = ['README.md', 'composer.json', 'NOTICE', '.gitignore', 'LICENSE.txt', 'example.php.txt', 'UPPER.PHP'];

        foreach ($packages as $package => $_) {
            mkdir($vendor.DIRECTORY_SEPARATOR.$package.DIRECTORY_SEPARATOR.'nested', 0755, true);
            file_put_contents($vendor.DIRECTORY_SEPARATOR.$package.DIRECTORY_SEPARATOR.'LICENSE', 'License text');
            file_put_contents($vendor.DIRECTORY_SEPARATOR.$package.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'Example.php', '<?php // source');

            foreach ($excluded as $name) {
                file_put_contents($vendor.DIRECTORY_SEPARATOR.$package.DIRECTORY_SEPARATOR.$name, 'Excluded content');
                file_put_contents($vendor.DIRECTORY_SEPARATOR.$package.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.$name, 'Excluded nested content');
            }
        }

        $manifest = (new Source)->import($vendor, $core);

        foreach ($packages as $destination) {
            $this->assertSame('License text', file_get_contents($core.DIRECTORY_SEPARATOR.$destination.DIRECTORY_SEPARATOR.'LICENSE'));
            $this->assertSame('<?php // source', file_get_contents($core.DIRECTORY_SEPARATOR.$destination.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'Example.php'));

            foreach ($excluded as $name) {
                $this->assertFileDoesNotExist($core.DIRECTORY_SEPARATOR.$destination.DIRECTORY_SEPARATOR.$name);
                $this->assertFileDoesNotExist($core.DIRECTORY_SEPARATOR.$destination.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.$name);
                $this->assertArrayNotHasKey($destination.DIRECTORY_SEPARATOR.$name, $manifest['files']);
                $this->assertArrayNotHasKey($destination.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.$name, $manifest['files']);
            }
        }

        $this->assertCount(2, $manifest['files']);
        $this->assertSame([], array_values(array_filter(array_keys($manifest['files']), static fn (string $file): bool => str_starts_with($file, 'Dependencies'.DIRECTORY_SEPARATOR))));
        $this->assertDirectoryDoesNotExist($core.DIRECTORY_SEPARATOR.'Dependencies');
    }

    #[Test]
    public function it_linked_destination_is_rejected_without_touching_target(): void
    {
        mkdir($this->directory.DIRECTORY_SEPARATOR.'outside');
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'outside'.DIRECTORY_SEPARATOR.'keep.txt', 'keep');
        symlink($this->directory.DIRECTORY_SEPARATOR.'outside', $this->directory.DIRECTORY_SEPARATOR.'core');
        $this->expectException(RuntimeException::class);

        try {
            (new Source)->import(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor', $this->directory.DIRECTORY_SEPARATOR.'core');
        } finally {
            $this->assertSame('keep', file_get_contents($this->directory.DIRECTORY_SEPARATOR.'outside'.DIRECTORY_SEPARATOR.'keep.txt'));
        }
    }

    #[Test]
    public function it_import_does_not_overwrite_an_unmanaged_component(): void
    {
        mkdir($this->directory.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'HttpFoundation', 0777, true);
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'HttpFoundation'.DIRECTORY_SEPARATOR.'keep.txt', 'keep');
        $this->expectException(RuntimeException::class);

        try {
            (new Source)->import(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'vendor', $this->directory.DIRECTORY_SEPARATOR.'core');
        } finally {
            $this->assertSame('keep', file_get_contents($this->directory.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'HttpFoundation'.DIRECTORY_SEPARATOR.'keep.txt'));
        }
    }

    #[Test]
    public function it_source_binary_help_uses_current_name_and_invalid_options_fail(): void
    {
        foreach ([['--help', 0, 'Usage: ./source'], ['--package=routing', 1, 'Only --package=http-foundation']] as [$argument, $status, $message]) {
            $process = proc_open([dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'source', $argument], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame($status, proc_close($process), $output);
            $this->assertStringContainsString($message, $output);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path.DIRECTORY_SEPARATOR.$entry);
            }

            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
