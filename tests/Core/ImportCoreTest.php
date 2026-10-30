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

    #[Test]
    public function it_imports_parsedown_as_an_isolated_managed_class(): void
    {
        $vendor = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor';
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        $manifest = new Source()->import($vendor, $core);
        $file = $core . DIRECTORY_SEPARATOR . 'Parsedown' . DIRECTORY_SEPARATOR . 'Parsedown.php';

        $this->assertArrayHasKey('erusev/parsedown', $manifest['packages']);
        $this->assertFileExists($file);
        $this->assertStringContainsString('namespace Arix\\Parsedown;', file_get_contents($file));
        $this->assertFileExists($core . DIRECTORY_SEPARATOR . 'Parsedown' . DIRECTORY_SEPARATOR . 'LICENSE');
    }

    protected function setUp(): void
    {
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-import-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    #[Test]
    public function it_import_relocates_sources_preserves_licenses_and_is_repeatable(): void
    {
        $vendor = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor';
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        $importer = new Source();
        $first = $importer->import($vendor, $core);

        $this->assertFileExists($core . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'LICENSE');
        $this->assertDirectoryDoesNotExist($core . DIRECTORY_SEPARATOR . 'Dependencies');
        $this->assertSame(['symfony/http-foundation', 'nikic/fast-route', 'eftec/bladeone', 'dragonmantank/cron-expression', 'erusev/parsedown'], array_keys($first['packages']));
        $this->assertFileExists($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'RouteCollector.php');
        $this->assertDirectoryDoesNotExist($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'src');
        $this->assertFileDoesNotExist($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'bootstrap.php');
        $this->assertArrayNotHasKey('FastRoute' . DIRECTORY_SEPARATOR . 'bootstrap.php', $first['files']);
        $this->assertFileExists($core . DIRECTORY_SEPARATOR . 'BladeOne' . DIRECTORY_SEPARATOR . 'BladeOne.php');
        $this->assertFileDoesNotExist($core . DIRECTORY_SEPARATOR . 'BladeOne' . DIRECTORY_SEPARATOR . 'bladeonecli');
        $this->assertDirectoryDoesNotExist($core . DIRECTORY_SEPARATOR . 'BladeOne' . DIRECTORY_SEPARATOR . 'bladeonecli');
        $this->assertSame(file_get_contents($vendor . DIRECTORY_SEPARATOR . 'nikic' . DIRECTORY_SEPARATOR . 'fast-route' . DIRECTORY_SEPARATOR . 'LICENSE'), file_get_contents($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'LICENSE'));
        $functions = file_get_contents($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'functions.php');
        $this->assertStringContainsString('namespace Arix\\FastRoute;', $functions);
        $this->assertStringContainsString('Arix\\FastRoute\\\\Dispatcher\\\\GroupCountBased', $functions);
        $this->assertStringNotContainsString('namespace FastRoute;', $functions);
        $this->assertSame(file_get_contents($vendor . DIRECTORY_SEPARATOR . 'symfony' . DIRECTORY_SEPARATOR . 'http-foundation' . DIRECTORY_SEPARATOR . 'LICENSE'), file_get_contents($core . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'LICENSE'));

        $source = file_get_contents($core . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'Request.php');

        $this->assertStringContainsString('namespace Arix\\HttpFoundation;', $source);
        $this->assertStringNotContainsString('Symfony\\Component\\HttpFoundation', $source);

        $views = file_get_contents($core . DIRECTORY_SEPARATOR . 'BladeOne' . DIRECTORY_SEPARATOR . 'BladeOne.php');

        $this->assertStringContainsString('namespace Arix\\BladeOne;', $views);
        $this->assertStringNotContainsString('eftec\\bladeone', $views);
        $this->assertStringNotContainsString('eftec\\\\bladeone', $views);
        $this->assertSame(file_get_contents($vendor . DIRECTORY_SEPARATOR . 'eftec' . DIRECTORY_SEPARATOR . 'bladeone' . DIRECTORY_SEPARATOR . 'LICENSE'), file_get_contents($core . DIRECTORY_SEPARATOR . 'BladeOne' . DIRECTORY_SEPARATOR . 'LICENSE'));

        file_put_contents($core . DIRECTORY_SEPARATOR . 'Kernel.php', '<?php // application-owned integration');

        $this->assertSame($first, $importer->import($vendor, $core));
        $this->assertSame('<?php // application-owned integration', file_get_contents($core . DIRECTORY_SEPARATOR . 'Kernel.php'));
        $this->assertSame($first, json_decode(file_get_contents($core . DIRECTORY_SEPARATOR . 'imports.json'), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_import_relocates_the_cron_namespace_without_rewriting_the_cron_expression_class(): void
    {
        $vendor = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor';
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        $manifest = (new Source())->import($vendor, $core);

        $this->assertFileExists($core . DIRECTORY_SEPARATOR . 'Cron' . DIRECTORY_SEPARATOR . 'CronExpression.php');
        $this->assertFileExists($core . DIRECTORY_SEPARATOR . 'Cron' . DIRECTORY_SEPARATOR . 'LICENSE');
        $this->assertArrayHasKey('Cron' . DIRECTORY_SEPARATOR . 'CronExpression.php', $manifest['files']);

        $source = file_get_contents($core . DIRECTORY_SEPARATOR . 'Cron' . DIRECTORY_SEPARATOR . 'CronExpression.php');

        $this->assertStringContainsString('namespace Arix\\Cron;', $source);
        $this->assertStringContainsString('class CronExpression', $source);
        $this->assertStringNotContainsString('Arix\\CronExpression', $source);
        $this->assertStringNotContainsString('namespace Cron;', $source);

        $factory = file_get_contents($core . DIRECTORY_SEPARATOR . 'Cron' . DIRECTORY_SEPARATOR . 'FieldFactory.php');

        $this->assertStringContainsString('namespace Arix\\Cron;', $factory);
        $this->assertStringContainsString('CronExpression::MINUTE', $factory);
        $this->assertStringNotContainsString('Arix\\CronExpression', $factory);
    }

    #[Test]
    public function it_missing_sources_do_not_change_existing_destination(): void
    {
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'core');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');

        $error = null;

        try {
            (new Source())->import($this->directory . DIRECTORY_SEPARATOR . 'missing', $this->directory . DIRECTORY_SEPARATOR . 'core');
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertSame('keep', file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'keep.txt'));
        $this->assertFileDoesNotExist($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'imports.json');
    }

    #[Test]
    public function it_import_copies_only_php_files_and_explicit_license_exception(): void
    {
        $vendor = $this->directory . DIRECTORY_SEPARATOR . 'vendor';
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        $packages = [
            'symfony/http-foundation' => 'HttpFoundation',
            'nikic/fast-route' => 'FastRoute',
            'eftec/bladeone' => 'BladeOne',
            'dragonmantank/cron-expression' => 'Cron',
        ];
        $roots = [
            'nikic/fast-route' => 'src',
            'eftec/bladeone' => 'lib',
            'dragonmantank/cron-expression' => 'src/Cron',
        ];
        $excluded = ['README.md', 'composer.json', 'NOTICE', '.gitignore', 'LICENSE.txt', 'example.php.txt', 'UPPER.PHP'];

        foreach ($packages as $package => $_) {
            $root = $roots[$package] ?? '';
            $source = $vendor . DIRECTORY_SEPARATOR . $package . ($root === '' ? '' : DIRECTORY_SEPARATOR . $root);
            mkdir($source . DIRECTORY_SEPARATOR . 'nested', 0755, true);
            file_put_contents($vendor . DIRECTORY_SEPARATOR . $package . DIRECTORY_SEPARATOR . 'LICENSE', 'License text');
            file_put_contents($source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'Example.php', '<?php // source');

            if ($package === 'nikic/fast-route') {
                file_put_contents($vendor . DIRECTORY_SEPARATOR . $package . DIRECTORY_SEPARATOR . 'not-in-src.php', '<?php // not source');
            }

            if ($package === 'eftec/bladeone') {
                file_put_contents($vendor . DIRECTORY_SEPARATOR . $package . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bladeonecli', '<?php // cli helper without extension');
            }

            foreach ($excluded as $name) {
                file_put_contents($vendor . DIRECTORY_SEPARATOR . $package . DIRECTORY_SEPARATOR . $name, 'Excluded content');
                file_put_contents($source . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . $name, 'Excluded nested content');
            }
        }

        $parsedown = $vendor . DIRECTORY_SEPARATOR . 'erusev' . DIRECTORY_SEPARATOR . 'parsedown';
        mkdir($parsedown, 0755, true);
        file_put_contents($parsedown . DIRECTORY_SEPARATOR . 'LICENSE.txt', 'Parsedown license');
        file_put_contents($parsedown . DIRECTORY_SEPARATOR . 'Parsedown.php', "<?php\nclass Parsedown {}\n");

        $manifest = (new Source())->import($vendor, $core);

        foreach ($packages as $destination) {
            $this->assertSame('License text', file_get_contents($core . DIRECTORY_SEPARATOR . $destination . DIRECTORY_SEPARATOR . 'LICENSE'));
            $this->assertSame('<?php // source', file_get_contents($core . DIRECTORY_SEPARATOR . $destination . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'Example.php'));

            foreach ($excluded as $name) {
                $this->assertFileDoesNotExist($core . DIRECTORY_SEPARATOR . $destination . DIRECTORY_SEPARATOR . $name);
                $this->assertFileDoesNotExist($core . DIRECTORY_SEPARATOR . $destination . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . $name);
                $this->assertArrayNotHasKey($destination . DIRECTORY_SEPARATOR . $name, $manifest['files']);
                $this->assertArrayNotHasKey($destination . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . $name, $manifest['files']);
            }
        }

        $this->assertCount(10, $manifest['files']);
        $this->assertFileDoesNotExist($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'not-in-src.php');
        $this->assertFileDoesNotExist($core . DIRECTORY_SEPARATOR . 'BladeOne' . DIRECTORY_SEPARATOR . 'bladeonecli');
        $this->assertSame([], array_values(array_filter(array_keys($manifest['files']), static fn (string $file): bool => str_starts_with($file, 'Dependencies' . DIRECTORY_SEPARATOR))));
        $this->assertDirectoryDoesNotExist($core . DIRECTORY_SEPARATOR . 'Dependencies');
    }

    #[Test]
    public function it_linked_destination_is_rejected_without_touching_target(): void
    {
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'outside');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'outside' . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');
        symlink($this->directory . DIRECTORY_SEPARATOR . 'outside', $this->directory . DIRECTORY_SEPARATOR . 'core');
        $this->expectException(RuntimeException::class);

        try {
            (new Source())->import(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor', $this->directory . DIRECTORY_SEPARATOR . 'core');
        } finally {
            $this->assertSame('keep', file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'outside' . DIRECTORY_SEPARATOR . 'keep.txt'));
        }
    }

    #[Test]
    public function it_import_does_not_overwrite_an_unmanaged_component(): void
    {
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'HttpFoundation', 0777, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');
        $this->expectException(RuntimeException::class);

        try {
            (new Source())->import(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor', $this->directory . DIRECTORY_SEPARATOR . 'core');
        } finally {
            $this->assertSame('keep', file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'keep.txt'));
        }
    }

    #[Test]
    public function it_source_binary_help_uses_current_name_and_invalid_options_fail(): void
    {
        foreach ([['--help', 0, 'Usage: ./source'], ['--package=unknown', 1, 'Unsupported source option']] as [$argument, $status, $message]) {
            $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'source', $argument], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);
            $this->assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame($status, proc_close($process), $output);
            $this->assertStringContainsString($message, $output);
        }
    }

    #[Test]
    public function it_upgrades_a_historical_manifest_and_protects_new_and_modified_components(): void
    {
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        mkdir($core . DIRECTORY_SEPARATOR . 'HttpFoundation', 0755, true);
        file_put_contents($core . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'LICENSE', 'old license');
        $manifest = ['format' => 1, 'packages' => ['symfony/http-foundation' => ['destination' => 'HttpFoundation']], 'files' => ['HttpFoundation' . DIRECTORY_SEPARATOR . 'LICENSE' => hash('sha256', 'old license')]];
        file_put_contents($core . DIRECTORY_SEPARATOR . 'imports.json', json_encode($manifest));
        $vendor = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor';
        mkdir($core . DIRECTORY_SEPARATOR . 'FastRoute');
        file_put_contents($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'keep.txt', 'keep');

        $error = null;

        try {
            new Source()->import($vendor, $core);
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertSame('keep', file_get_contents($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'keep.txt'));

        $this->remove($core . DIRECTORY_SEPARATOR . 'FastRoute');
        $updated = new Source()->import($vendor, $core);
        $this->assertArrayHasKey('nikic/fast-route', $updated['packages']);
        file_put_contents($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'Dispatcher.php', 'local edits');
        $this->expectException(RuntimeException::class);

        try {
            new Source()->import($vendor, $core);
        } finally {
            $this->assertSame('local edits', file_get_contents($core . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'Dispatcher.php'));
        }
    }

    #[Test]
    public function it_restores_components_and_manifest_when_an_install_move_fails(): void
    {
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        $vendor = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor';
        $first = new Source()->import($vendor, $core);
        file_put_contents($core . DIRECTORY_SEPARATOR . 'handwritten.php', '<?php // preserve');
        $importer = new class() extends Source
        {
            protected function move(string $from, string $to): bool
            {
                if (basename($to) === 'FastRoute' && str_starts_with(basename(dirname($from)), '.import-')) {
                    return false;
                }

                return parent::move($from, $to);
            }
        };

        $error = null;

        try {
            $importer->import($vendor, $core);
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertSame('Cannot install FastRoute', $error->getMessage());
        $this->assertSame($first, json_decode(file_get_contents($core . DIRECTORY_SEPARATOR . 'imports.json'), true));

        foreach ($first['files'] as $name => $hash) {
            $this->assertSame($hash, hash_file('sha256', $core . DIRECTORY_SEPARATOR . $name));
        }

        $this->assertSame('<?php // preserve', file_get_contents($core . DIRECTORY_SEPARATOR . 'handwritten.php'));
        $this->assertSame($first, new Source()->import($vendor, $core));
        $this->assertSame([], glob($core . DIRECTORY_SEPARATOR . '.import-*'));
    }

    #[Test]
    public function it_rejects_a_linked_fastroute_package_root_before_changing_core(): void
    {
        $vendor = $this->directory . DIRECTORY_SEPARATOR . 'vendor';
        mkdir($vendor . DIRECTORY_SEPARATOR . 'symfony' . DIRECTORY_SEPARATOR . 'http-foundation', 0755, true);
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'symfony' . DIRECTORY_SEPARATOR . 'http-foundation' . DIRECTORY_SEPARATOR . 'LICENSE', 'license');
        mkdir($vendor . DIRECTORY_SEPARATOR . 'nikic', 0755, true);
        symlink(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'nikic' . DIRECTORY_SEPARATOR . 'fast-route', $vendor . DIRECTORY_SEPARATOR . 'nikic' . DIRECTORY_SEPARATOR . 'fast-route');
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        $error = null;

        try {
            new Source()->import($vendor, $core);
        } catch (RuntimeException $exception) {
            $error = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertFileDoesNotExist($core . DIRECTORY_SEPARATOR . 'imports.json');
        $this->assertDirectoryDoesNotExist($core . DIRECTORY_SEPARATOR . 'FastRoute');
    }

    #[Test]
    public function it_preserves_http_foundation_bytes_during_manifest_upgrade(): void
    {
        $core = $this->directory . DIRECTORY_SEPARATOR . 'core';
        $vendor = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor';
        $manifest = new Source()->import($vendor, $core);
        unset($manifest['packages']['nikic/fast-route'], $manifest['packages']['eftec/bladeone'], $manifest['packages']['dragonmantank/cron-expression'], $manifest['packages']['erusev/parsedown']);
        $manifest['files'] = array_filter($manifest['files'], static fn (string $name): bool => str_starts_with($name, 'HttpFoundation' . DIRECTORY_SEPARATOR), ARRAY_FILTER_USE_KEY);
        $this->remove($core . DIRECTORY_SEPARATOR . 'FastRoute');
        $this->remove($core . DIRECTORY_SEPARATOR . 'BladeOne');
        $this->remove($core . DIRECTORY_SEPARATOR . 'Cron');
        $this->remove($core . DIRECTORY_SEPARATOR . 'Parsedown');
        file_put_contents($core . DIRECTORY_SEPARATOR . 'imports.json', json_encode($manifest));
        $before = file_get_contents($core . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'Request.php');
        $updated = new Source()->import($vendor, $core);
        $this->assertSame($before, file_get_contents($core . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'Request.php'));

        foreach ($manifest['files'] as $name => $hash) {
            $this->assertSame($hash, $updated['files'][$name]);
        }
    }

    #[Test]
    public function it_imports_the_full_bundle_for_both_cli_package_aliases(): void
    {
        $root = dirname(__DIR__, 2);
        $vendor = $this->directory . DIRECTORY_SEPARATOR . 'vendor';
        mkdir($vendor . DIRECTORY_SEPARATOR . 'symfony' . DIRECTORY_SEPARATOR . 'http-foundation', 0755, true);
        mkdir($vendor . DIRECTORY_SEPARATOR . 'nikic' . DIRECTORY_SEPARATOR . 'fast-route' . DIRECTORY_SEPARATOR . 'src', 0755, true);
        mkdir($vendor . DIRECTORY_SEPARATOR . 'eftec' . DIRECTORY_SEPARATOR . 'bladeone' . DIRECTORY_SEPARATOR . 'lib', 0755, true);
        mkdir($vendor . DIRECTORY_SEPARATOR . 'dragonmantank' . DIRECTORY_SEPARATOR . 'cron-expression' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Cron', 0755, true);
        mkdir($vendor . DIRECTORY_SEPARATOR . 'erusev' . DIRECTORY_SEPARATOR . 'parsedown', 0755, true);
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'erusev' . DIRECTORY_SEPARATOR . 'parsedown' . DIRECTORY_SEPARATOR . 'LICENSE.txt', 'license');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'erusev' . DIRECTORY_SEPARATOR . 'parsedown' . DIRECTORY_SEPARATOR . 'Parsedown.php', "<?php\nclass Parsedown {}\n");
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'autoload.php', '<?php require ' . var_export($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', true) . ';');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'symfony' . DIRECTORY_SEPARATOR . 'http-foundation' . DIRECTORY_SEPARATOR . 'LICENSE', 'license');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'nikic' . DIRECTORY_SEPARATOR . 'fast-route' . DIRECTORY_SEPARATOR . 'LICENSE', 'license');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'eftec' . DIRECTORY_SEPARATOR . 'bladeone' . DIRECTORY_SEPARATOR . 'LICENSE', 'license');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'dragonmantank' . DIRECTORY_SEPARATOR . 'cron-expression' . DIRECTORY_SEPARATOR . 'LICENSE', 'license');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'nikic' . DIRECTORY_SEPARATOR . 'fast-route' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Example.php', '<?php namespace FastRoute;');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'eftec' . DIRECTORY_SEPARATOR . 'bladeone' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'Example.php', '<?php namespace eftec\\bladeone;');
        file_put_contents($vendor . DIRECTORY_SEPARATOR . 'dragonmantank' . DIRECTORY_SEPARATOR . 'cron-expression' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Cron' . DIRECTORY_SEPARATOR . 'Example.php', '<?php namespace Cron;');
        $source = $this->directory . DIRECTORY_SEPARATOR . 'source';
        copy($root . DIRECTORY_SEPARATOR . 'source', $source);

        foreach (['--package=http-foundation', '--package=routing'] as $argument) {
            $process = proc_open([PHP_BINARY, $source, $argument], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $this->assertStringContainsString('Imported 5 packages', $output);
            $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'FastRoute' . DIRECTORY_SEPARATOR . 'Example.php');
            $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'BladeOne' . DIRECTORY_SEPARATOR . 'Example.php');
            $this->assertFileExists($this->directory . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'HttpFoundation' . DIRECTORY_SEPARATOR . 'LICENSE');
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
