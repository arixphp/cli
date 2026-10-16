<?php

declare(strict_types=1);

namespace Tests\Unit\Tap;

use Arix\Tap\Formula;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ApplicationTestingFixture;

class FormulaTest extends TestCase
{
    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR;
        $this->assertFileExists("{$root}release" . DIRECTORY_SEPARATOR . 'Release.php');
        $this->assertFileExists("{$root}tap" . DIRECTORY_SEPARATOR . 'Formula.php');
        require_once "{$root}release" . DIRECTORY_SEPARATOR . 'Release.php';
        require_once "{$root}tap" . DIRECTORY_SEPARATOR . 'Formula.php';
    }

    /** @return array<string, string> */
    private function checksums(): array
    {
        return [
            'arix-1.2.3-linux-x64.tar.gz' => str_repeat('a', 64),
            'arix-1.2.3-linux-arm64.tar.gz' => str_repeat('b', 64),
            'arix-1.2.3-macos-x64.tar.gz' => str_repeat('c', 64),
            'arix-1.2.3-macos-arm64.tar.gz' => str_repeat('d', 64),
        ];
    }

    #[Test]
    public function it_writes_a_formula_with_version_urls_and_checksums(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            Formula::update($fixture->path('tap'), '1.2.3', $this->checksums());
            $formula = file_get_contents($fixture->path('tap/Formula/arix.rb'));
            $this->assertIsString($formula);
            $this->assertStringContainsString('version "1.2.3"', $formula);
            $this->assertStringContainsString('releases/download/1.2.3/arix-1.2.3-linux-x64.tar.gz', $formula);
            $this->assertStringContainsString('releases/download/1.2.3/arix-1.2.3-macos-arm64.tar.gz', $formula);
            $this->assertStringContainsString(str_repeat('a', 64), $formula);
            $this->assertStringContainsString(str_repeat('d', 64), $formula);
            $this->assertStringContainsString('bin.install "arix"', $formula);
            $this->assertStringNotContainsString('windows', $formula);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_accepts_legacy_prefixed_tags_but_writes_bare_versions(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            Formula::update($fixture->path('tap'), 'v1.2.3', $this->checksums());
            $formula = file_get_contents($fixture->path('tap/Formula/arix.rb'));
            $this->assertIsString($formula);
            $this->assertStringContainsString('version "1.2.3"', $formula);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_rejects_incomplete_or_invalid_checksums(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $incomplete = $this->checksums();
            unset($incomplete['arix-1.2.3-macos-x64.tar.gz']);

            try {
                Formula::update($fixture->path('tap'), '1.2.3', $incomplete);
                $this->fail('Accepted incomplete checksums.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }

            $invalid = $this->checksums();
            $invalid['arix-1.2.3-linux-x64.tar.gz'] = 'not-a-sha256';

            try {
                Formula::update($fixture->path('tap'), '1.2.3', $invalid);
                $this->fail('Accepted an invalid checksum.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }

            $this->assertFileDoesNotExist($fixture->path('tap/Formula/arix.rb'));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_rejects_invalid_versions_before_writing_anything(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            foreach (['nope', 'v', '1.2', ''] as $version) {
                try {
                    Formula::update($fixture->path('tap'), $version, $this->checksums());
                    $this->fail("Accepted invalid version {$version}");
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }

            $this->assertFileDoesNotExist($fixture->path('tap/Formula/arix.rb'));
        } finally {
            $fixture->cleanup();
        }
    }
}
