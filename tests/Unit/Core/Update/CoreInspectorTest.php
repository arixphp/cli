<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\ApplicationTarget;
use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CoreUpdateFixture;

class CoreInspectorTest extends TestCase
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
    public function it_resolves_default_relative_and_absolute_application_roots(): void
    {
        $application = $this->fixture->installed('my application');
        $this->assertSame($application, ApplicationTarget::resolve(null, $application));
        $this->assertSame($application, ApplicationTarget::resolve('my application', $this->fixture->root));
        $this->assertSame($application, ApplicationTarget::resolve($application, $this->fixture->root));
    }

    #[Test]
    public function it_rejects_legacy_and_nonexistent_applications(): void
    {
        $application = $this->fixture->installed();
        unlink($this->fixture->path('application/.arix.json'));

        try {
            ApplicationTarget::resolve($application, $this->fixture->root);
            $this->fail('Legacy application accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('compatibility', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        ApplicationTarget::resolve('missing', $this->fixture->root);
    }

    #[Test]
    public function it_rejects_linked_targets_and_source_overlap(): void
    {
        $application = $this->fixture->installed();
        symlink($application, $this->fixture->path('linked'));

        try {
            ApplicationTarget::resolve('linked', $this->fixture->root);
            $this->fail('Linked application accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('link', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('overlap');
        ApplicationTarget::resolve($application, $this->fixture->root, $this->fixture->path('application/core'));
    }

    #[Test]
    public function it_previews_added_modified_and_removed_files_without_writing(): void
    {
        $application = $this->fixture->installed(files: ['Old.php' => '<?php // retired']);
        $source = $this->fixture->core('source', ['New.php' => '<?php // added', 'Http/Kernel.php' => '<?php // new kernel']);
        CoreDistribution::build($source, $this->fixture->path('core.bundle'));
        $result = new CoreInspector()->inspect($application, CoreDistribution::load($this->fixture->path('core.bundle')));

        $this->assertSame(['New.php'], $result['added']);
        $this->assertSame(['Http/Kernel.php'], $result['modified']);
        $this->assertSame(['Old.php'], $result['removed']);
        $this->assertFileExists($this->fixture->path('application/core/Old.php'));
        $this->assertFileDoesNotExist($this->fixture->path('application/core/New.php'));
        $this->assertFileDoesNotExist($this->fixture->path('application/.core-update.lock'));
    }

    #[Test]
    public function it_rejects_local_edits_missing_files_and_unmanaged_additions(): void
    {
        $source = $this->fixture->core('source');
        CoreDistribution::build($source, $this->fixture->path('core.bundle'));
        $distribution = CoreDistribution::load($this->fixture->path('core.bundle'));

        foreach (['edited', 'missing', 'extra'] as $case) {
            $application = $this->fixture->installed($case);

            if ($case === 'edited') {
                $this->fixture->write("{$case}/core/functions.php", 'edited');
            } elseif ($case === 'missing') {
                unlink($this->fixture->path("{$case}/core/functions.php"));
            } else {
                $this->fixture->write("{$case}/core/custom.php", 'extra');
            }

            try {
                new CoreInspector()->inspect($application, $distribution);
                $this->fail("Accepted {$case} core.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('local changes', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_requires_an_installed_manifest_and_blocks_pending_recovery(): void
    {
        CoreDistribution::build($this->fixture->core('source'), $this->fixture->path('core.bundle'));
        $distribution = CoreDistribution::load($this->fixture->path('core.bundle'));
        $application = $this->fixture->installed();
        unlink($this->fixture->path('application/core/distribution.json'));

        try {
            new CoreInspector()->inspect($application, $distribution);
            $this->fail('Unmanaged core accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('manifest', $exception->getMessage());
        }

        $this->fixture->write('application/.core-update.json', '{"state":"installing"}');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('recovery');
        new CoreInspector()->inspect($application, $distribution);
    }

    #[Test]
    public function it_rejects_unmanaged_empty_directories_in_the_installed_core(): void
    {
        CoreDistribution::build($this->fixture->core('source'), $this->fixture->path('core.bundle'));
        $application = $this->fixture->installed();
        mkdir($this->fixture->path('application/core/UserWork'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('local changes');
        new CoreInspector()->inspect($application, CoreDistribution::load($this->fixture->path('core.bundle')));
    }

    #[Test]
    public function it_rejects_application_metadata_declaring_a_retired_core_contract(): void
    {
        $application = $this->fixture->installed();
        $this->fixture->write('application/.arix.json', '{"format":1,"core_contract":"standalone-http-v1"}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('compatibility');
        ApplicationTarget::resolve($application, $this->fixture->root);
    }
}
