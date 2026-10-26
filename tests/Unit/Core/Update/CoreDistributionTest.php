<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CoreUpdateFixture;

class CoreDistributionTest extends TestCase
{
    private CoreUpdateFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new CoreUpdateFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_accepts_previous_installed_manifests_without_new_resources(): void
    {
        $files = [
            'Http/Kernel.php' => hash('sha256', 'kernel'),
            'autoload.php' => hash('sha256', 'autoload'),
            'Autoload/AutoloadConfiguration.php' => hash('sha256', 'configuration'),
            'Autoload/ClassLoader.php' => hash('sha256', 'loader'),
            'functions.php' => hash('sha256', 'functions'),
            'imports.json' => hash('sha256', 'imports'),
        ];
        $manifest = CoreDistribution::manifest($files);

        ksort($files);
        $this->assertSame($files, CoreDistribution::validateManifest($manifest)['files']);
    }

    #[Test]
    public function it_rejects_runnable_distributions_missing_native_autoload_declarations(): void
    {
        foreach (['Autoload/AutoloadConfiguration.php', 'Autoload/ClassLoader.php'] as $missing) {
            $files = [
                'Http/Kernel.php' => hash('sha256', 'kernel'),
                'autoload.php' => hash('sha256', 'autoload'),
                'Autoload/AutoloadConfiguration.php' => hash('sha256', 'configuration'),
                'Autoload/ClassLoader.php' => hash('sha256', 'loader'),
                'functions.php' => hash('sha256', 'functions'),
                'imports.json' => hash('sha256', 'imports'),
            ];
            unset($files[$missing]);
            $manifest = CoreDistribution::manifest($files);

            try {
                CoreDistribution::validateManifest($manifest);
                $this->fail("Distribution without {$missing} was accepted.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Incomplete core distribution', $exception->getMessage());
                $this->assertStringContainsString($missing, $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_packages_exact_bytes_and_excludes_development_artifacts(): void
    {
        $source = $this->fixture->core('source');
        $this->fixture->write('source/.DS_Store', 'junk');
        $this->fixture->write('source/.import.lock', '');
        $this->fixture->write('source/Config/README.md', 'development documentation');
        $bundle = $this->fixture->path('core.bundle');

        CoreDistribution::build($source, $bundle);
        $distribution = CoreDistribution::load($bundle);

        $this->assertSame(['.htaccess', 'Assets/public-policy.json', 'Autoload/AutoloadConfiguration.php', 'Autoload/ClassLoader.php', 'Http/Kernel.php', 'autoload.php', 'functions.php', 'imports.json', 'stubs/error.html'], array_keys($distribution->manifest['files']));
        $this->assertSame("<?php\ndeclare(strict_types=1);\n// kernel\n", $distribution->contents('Http/Kernel.php'));
        $this->assertSame(hash('sha256', $distribution->contents('Http/Kernel.php')), $distribution->manifest['files']['Http/Kernel.php']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $distribution->manifest['id']);
    }

    #[Test]
    public function it_rejects_corrupted_bundle_bytes(): void
    {
        CoreDistribution::build($this->fixture->core('source'), $this->fixture->path('core.bundle'));
        $data = json_decode(file_get_contents($this->fixture->path('core.bundle')), true, flags: JSON_THROW_ON_ERROR);
        $data['contents']['autoload.php'] = base64_encode('corrupt');
        $this->fixture->write('core.bundle', json_encode($data, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('hash');
        CoreDistribution::load($this->fixture->path('core.bundle'));
    }

    #[Test]
    public function it_rejects_unsafe_manifest_paths(): void
    {
        CoreDistribution::build($this->fixture->core('source'), $this->fixture->path('core.bundle'));
        $data = json_decode(file_get_contents($this->fixture->path('core.bundle')), true, flags: JSON_THROW_ON_ERROR);
        $data['manifest']['files']['../outside.php'] = hash('sha256', 'bad');
        $data['contents']['../outside.php'] = base64_encode('bad');
        $this->fixture->write('core.bundle', json_encode($data, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('path');
        CoreDistribution::load($this->fixture->path('core.bundle'));
    }

    #[Test]
    public function it_rejects_modified_imported_sources_before_packaging(): void
    {
        $source = $this->fixture->core('source');
        $this->fixture->write('source/FastRoute/functions.php', '<?php // edited');
        $this->fixture->write('source/imports.json', json_encode([
            'format' => 1,
            'packages' => ['nikic/fast-route' => ['destination' => 'FastRoute']],
            'files' => ['FastRoute/functions.php' => hash('sha256', '<?php // original')],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Imported');
        CoreDistribution::build($source, $this->fixture->path('core.bundle'));
    }

    #[Test]
    public function it_rejects_symlinks_and_unfinished_imports(): void
    {
        $source = $this->fixture->core('source');
        symlink($this->fixture->path('source/autoload.php'), $this->fixture->path('source/link.php'));

        try {
            CoreDistribution::build($source, $this->fixture->path('core.bundle'));
            $this->fail('Linked source was packaged.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('link', $exception->getMessage());
        }

        unlink($this->fixture->path('source/link.php'));
        $this->fixture->write('source/.import-pending/previous/backup.php', 'backup');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('import');
        CoreDistribution::build($source, $this->fixture->path('core.bundle'));
    }

    #[Test]
    public function it_rejects_a_stale_development_bundle(): void
    {
        $this->fixture->core('cli/core');
        mkdir($this->fixture->path('cli/resources'));
        CoreDistribution::build($this->fixture->path('cli/core'), $this->fixture->path('cli/resources/core.bundle'));
        $this->fixture->write('cli/core/Http/Kernel.php', '<?php // changed since packaging');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('core:package');
        CoreDistribution::bundled($this->fixture->path('cli'));
    }

    #[Test]
    public function it_reports_invalid_manifest_shapes_as_validation_errors(): void
    {
        $this->fixture->write('core.bundle', '{"manifest":"invalid","contents":{}}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('manifest');
        CoreDistribution::load($this->fixture->path('core.bundle'));
    }

    #[Test]
    public function it_rejects_linked_bundle_files(): void
    {
        CoreDistribution::build($this->fixture->core('source'), $this->fixture->path('core.bundle'));
        symlink($this->fixture->path('core.bundle'), $this->fixture->path('linked.bundle'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('link');
        CoreDistribution::load($this->fixture->path('linked.bundle'));
    }

    #[Test]
    public function it_rejects_file_directory_conflicts_in_manifest_paths(): void
    {
        CoreDistribution::build($this->fixture->core('source'), $this->fixture->path('core.bundle'));
        $data = json_decode(file_get_contents($this->fixture->path('core.bundle')), true, flags: JSON_THROW_ON_ERROR);
        $data['manifest']['files']['autoload.php/Child.php'] = hash('sha256', 'child');
        ksort($data['manifest']['files']);
        unset($data['manifest']['id']);
        $data['manifest']['id'] = hash('sha256', json_encode($data['manifest'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $data['contents']['autoload.php/Child.php'] = base64_encode('child');
        ksort($data['contents']);
        $this->fixture->write('core.bundle', json_encode($data, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('path');
        CoreDistribution::load($this->fixture->path('core.bundle'));
    }

    #[Test]
    public function it_rejects_unmanaged_imported_sources_when_packaging(): void
    {
        $source = $this->fixture->core('source');
        $this->fixture->write('source/FastRoute/functions.php', '<?php // unmanaged upstream file');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Imported');
        CoreDistribution::build($source, $this->fixture->path('core.bundle'));
    }

    #[Test]
    public function it_declares_the_arix_core_contract_identifier(): void
    {
        $files = [
            'Http/Kernel.php' => hash('sha256', 'kernel'),
            'autoload.php' => hash('sha256', 'autoload'),
            'Autoload/AutoloadConfiguration.php' => hash('sha256', 'configuration'),
            'Autoload/ClassLoader.php' => hash('sha256', 'loader'),
            'functions.php' => hash('sha256', 'functions'),
            'imports.json' => hash('sha256', 'imports'),
        ];
        $manifest = CoreDistribution::manifest($files);

        $this->assertSame('arix-core-v1', $manifest['contract']);
        $this->assertSame($manifest, CoreDistribution::validateManifest($manifest));
    }
}
