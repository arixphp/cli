<?php

declare(strict_types=1);

namespace Tests\Unit\Js\Update;

use App\Js\Update\JsDistribution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\JsUpdateFixture;

class JsDistributionTest extends TestCase
{
    private JsUpdateFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new JsUpdateFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_loads_version_hash_and_contents_from_a_bundled_directory(): void
    {
        $body = $this->fixture->framework('2.0.0');
        $root = $this->fixture->distribution('dist', '2.0.0', $body, ['1.0.0' => str_repeat('0', 64)]);
        $distribution = JsDistribution::bundled($root);

        $this->assertSame('2.0.0', $distribution->version);
        $this->assertSame(hash('sha256', $body), $distribution->hash);
        $this->assertSame($body, $distribution->contents());
        $this->assertTrue($distribution->isKnownRelease($distribution->hash));
        $this->assertTrue($distribution->isKnownRelease(str_repeat('0', 64)));
        $this->assertSame('2.0.0', $distribution->releaseOf($distribution->hash));
        $this->assertSame('1.0.0', $distribution->releaseOf(str_repeat('0', 64)));
        $this->assertNull($distribution->releaseOf(str_repeat('1', 64)));
    }

    #[Test]
    public function it_rejects_a_manifest_with_an_unknown_format(): void
    {
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->framework('2.0.0'));
        $this->fixture->write('dist/resources/js/manifest.json', '{"format":2,"releases":{"2.0.0":"' . str_repeat('0', 64) . '"}}');

        $this->expectException(RuntimeException::class);
        JsDistribution::bundled($root);
    }

    #[Test]
    public function it_rejects_a_canonical_file_without_a_recorded_release(): void
    {
        $root = $this->fixture->distribution('dist', '2.0.0', $this->fixture->framework('2.0.0'));
        $this->fixture->write('dist/resources/js/manifest.json', '{"format":1,"releases":{"1.0.0":"' . str_repeat('0', 64) . '"}}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('release');
        JsDistribution::bundled($root);
    }

    #[Test]
    public function it_rejects_a_bundle_without_a_framework_version(): void
    {
        $root = $this->fixture->path('dist');
        $this->fixture->write('dist/resources/js/arix.js', "// no version here\nexport const other = 1;\n");
        $this->fixture->write('dist/resources/js/manifest.json', '{"format":1,"releases":{"1.0.0":"' . str_repeat('0', 64) . '"}}');

        $this->expectException(RuntimeException::class);
        JsDistribution::bundled($root);
    }

    #[Test]
    public function it_parses_double_quoted_versions(): void
    {
        $this->assertSame('3.1.0', JsDistribution::parseVersion("export const version = \"3.1.0\";\n"));
    }
}
