<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CorePackager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CoreUpdateFixture;

class CorePackagerTest extends TestCase
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
    public function it_builds_a_verified_bundle_and_creates_its_resource_directory(): void
    {
        $source = $this->fixture->core('cli/core');
        $bundle = $this->fixture->path('cli/resources/core.bundle');
        $distribution = new CorePackager($source, $bundle)->build();

        $this->assertSame("<?php\ndeclare(strict_types=1);\n// kernel\n", $distribution->contents('Http/Kernel.php'));
        $this->assertSame($distribution->manifest['id'], CoreDistribution::load($bundle)->manifest['id']);
    }

    #[Test]
    public function it_checks_without_rewriting_the_bundle(): void
    {
        $source = $this->fixture->core('cli/core');
        $bundle = $this->fixture->path('cli/resources/core.bundle');
        $packager = new CorePackager($source, $bundle);
        $distribution = $packager->build();
        touch($bundle, 10000);
        $bytes = file_get_contents($bundle);

        $checked = $packager->check();
        clearstatcache(true, $bundle);

        $this->assertSame($distribution->manifest['id'], $checked->manifest['id']);
        $this->assertSame($bytes, file_get_contents($bundle));
        $this->assertSame(10000, filemtime($bundle));
    }

    #[Test]
    public function it_rejects_a_stale_bundle_without_replacing_it(): void
    {
        $source = $this->fixture->core('cli/core');
        $bundle = $this->fixture->path('cli/resources/core.bundle');
        $packager = new CorePackager($source, $bundle);
        $packager->build();
        $bytes = file_get_contents($bundle);
        $this->fixture->write('cli/core/Http/Kernel.php', '<?php // new kernel');

        try {
            $packager->check();
            $this->fail('Stale bundle accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('core:package', $exception->getMessage());
        }

        $this->assertSame($bytes, file_get_contents($bundle));
    }

    #[Test]
    public function it_rejects_compiled_sources_before_trying_to_write(): void
    {
        $source = 'phar://compiled.phar/core';
        $bundle = $this->fixture->path('resources/core.bundle');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('development');
        new CorePackager($source, $bundle)->build();
    }

    #[Test]
    public function it_does_not_create_resources_when_source_integrity_fails(): void
    {
        $source = $this->fixture->core('cli/core');
        $bundle = $this->fixture->path('cli/resources/core.bundle');
        $this->fixture->write('cli/core/FastRoute/functions.php', '<?php // unmanaged');

        try {
            new CorePackager($source, $bundle)->build();
            $this->fail('Invalid imported source accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Imported', $exception->getMessage());
        }

        $this->assertDirectoryDoesNotExist($this->fixture->path('cli/resources'));
    }
}
