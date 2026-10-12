<?php

declare(strict_types=1);

namespace Tests\Unit\Css\Update;

use App\Css\Update\CssDistribution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\CssUpdateFixture;

class CssDistributionTest extends TestCase
{
    private CssUpdateFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CssUpdateFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_loads_version_hashes_and_contents_from_a_bundled_directory(): void
    {
        $bodies = $this->fixture->versionSet('2.0.0');
        $old = $this->fixture->versionSet('1.0.0');
        $root = $this->fixture->distribution('dist', '2.0.0', $bodies, ['1.0.0' => $this->fixture->hashes($old)]);
        $distribution = CssDistribution::bundled($root);

        $this->assertSame('2.0.0', $distribution->version);
        $this->assertSame($this->fixture->hashes($bodies), $distribution->hashes);
        $this->assertSame($bodies['tokens.css'], $distribution->contents('tokens.css'));
        $this->assertTrue($distribution->isKnownSet($distribution->hashes));
        $this->assertTrue($distribution->isKnownSet($this->fixture->hashes($old)));
        $this->assertFalse($distribution->isKnownSet(['base.css' => str_repeat('0', 64)]));
        $this->assertSame('2.0.0', $distribution->releaseOf($distribution->hashes));
        $this->assertSame('1.0.0', $distribution->releaseOf($this->fixture->hashes($old)));
        $this->assertNull($distribution->releaseOf(['base.css' => str_repeat('0', 64)]));
    }

    #[Test]
    public function it_rejects_a_request_for_an_unmanaged_file(): void
    {
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->versionSet('2.0.0'));

        $this->expectException(RuntimeException::class);
        CssDistribution::bundled($root)->contents('app.css');
    }

    #[Test]
    public function it_rejects_a_manifest_with_an_unknown_format(): void
    {
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->versionSet('2.0.0'));
        $this->fixture->write('dist/resources/css/manifest.json', '{"format":2,"releases":{}}');

        $this->expectException(RuntimeException::class);
        CssDistribution::bundled($root);
    }

    #[Test]
    public function it_rejects_a_canonical_set_without_a_recorded_release(): void
    {
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->versionSet('2.0.0'));
        $this->fixture->write('dist/resources/css/manifest.json', '{"format":1,"releases":{"1.0.0":' . json_encode($this->fixture->hashes($this->fixture->versionSet('1.0.0'))) . '}}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('release');
        CssDistribution::bundled($root);
    }

    #[Test]
    public function it_rejects_a_release_missing_a_managed_file(): void
    {
        $hashes = $this->fixture->hashes($this->fixture->versionSet('2.0.0'));
        unset($hashes['tokens.css']);

        $this->expectException(RuntimeException::class);
        CssDistribution::validateManifest(['format' => 1, 'releases' => ['2.0.0' => $hashes]]);
    }
}
