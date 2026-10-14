<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use Arix\Release\Release;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ApplicationTestingFixture;
use ZipArchive;

class ReleaseTest extends TestCase
{
    protected function setUp(): void
    {
        $file = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.github' . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'Release.php';
        $this->assertFileExists($file);
        require_once $file;
    }

    #[Test]
    public function it_validates_versions_before_using_them_as_paths_or_arguments(): void
    {
        $this->assertSame('1.2.3', Release::version('v1.2.3'));
        $this->assertSame('1.2.3-rc.1+build.2', Release::version('v1.2.3-rc.1+build.2'));
        $this->assertTrue(Release::prerelease('v1.2.3-rc.1'));
        $this->assertFalse(Release::prerelease('v1.2.3+build.2'));

        foreach (['1.2.3', 'v01.2.3', 'v1.2', 'v1.2.3-01', 'v1.2.3/evil', "v1.2.3\n", '--help'] as $tag) {
            try {
                Release::version($tag);
                $this->fail("Accepted invalid tag {$tag}");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function it_requires_all_five_nonempty_archives_before_writing_checksums(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('dist/arix-1.2.3-linux-x64.tar.gz', 'linux');
            $this->expectException(RuntimeException::class);
            Release::checksums('v1.2.3', $fixture->path('dist'));
        } finally {
            $this->assertFileDoesNotExist($fixture->path('dist/SHA256SUMS'));
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_verifies_downloaded_assets_and_rejects_corruption(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            foreach (['linux-x64.tar.gz', 'linux-arm64.tar.gz', 'macos-x64.tar.gz', 'macos-arm64.tar.gz', 'windows-x64.zip'] as $target) {
                $fixture->write("dist/arix-1.2.3-{$target}", 'abc');
                $fixture->write("download/arix-1.2.3-{$target}", 'abc');
            }

            Release::checksums('v1.2.3', $fixture->path('dist'));
            $sums = file_get_contents($fixture->path('dist/SHA256SUMS'));
            $this->assertStringContainsString('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad  arix-1.2.3-linux-x64.tar.gz', $sums);
            $fixture->write('download/SHA256SUMS', $sums);
            Release::verify('v1.2.3', $fixture->path('dist'), $fixture->path('download'));
            $fixture->write('download/arix-1.2.3-windows-x64.zip', 'corrupt');
            $this->expectException(RuntimeException::class);
            Release::verify('v1.2.3', $fixture->path('dist'), $fixture->path('download'));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_rejects_empty_archives(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            foreach (['linux-x64.tar.gz', 'linux-arm64.tar.gz', 'macos-x64.tar.gz', 'macos-arm64.tar.gz', 'windows-x64.zip'] as $target) {
                $fixture->write("dist/arix-1.2.3-{$target}", 'abc');
            }

            $fixture->write('dist/arix-1.2.3-linux-x64.tar.gz', '');
            $this->expectException(RuntimeException::class);
            Release::checksums('v1.2.3', $fixture->path('dist'));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_rejects_linked_archives_before_upload(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('outside', 'abc');
            $fixture->write('dist/placeholder', 'abc');

            if (! @symlink($fixture->path('outside'), $fixture->path('dist/arix-1.2.3-linux-x64.tar.gz'))) {
                $this->markTestSkipped('This filesystem/user cannot create symlinks.');
            }

            $this->expectException(RuntimeException::class);
            Release::checksums('v1.2.3', $fixture->path('dist'));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_rejects_skipped_empty_and_failed_integration_reports(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $fixture->write('green.xml', '<testsuites><testsuite tests="2" skipped="0" failures="0" errors="0"><testcase name="one"/><testcase name="two"/></testsuite></testsuites>');
            Release::report($fixture->path('green.xml'));
            $this->addToAssertionCount(1);

            foreach (['<testsuites/>', '<testsuites><testsuite><testcase><skipped/></testcase></testsuite></testsuites>', '<testsuites><testsuite><testcase><failure/></testcase></testsuite></testsuites>', '<broken'] as $index => $xml) {
                $fixture->write("bad-{$index}.xml", $xml);

                try {
                    Release::report($fixture->path("bad-{$index}.xml"));
                    $this->fail('Accepted incomplete integration tests.');
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_checks_runtime_integrity_before_reading_the_micro_binary(): void
    {
        $fixture = new ApplicationTestingFixture();

        try {
            $path = $fixture->path('runtime.zip');
            $zip = new ZipArchive();
            $zip->open($path, ZipArchive::CREATE);
            $zip->addFromString('micro.sfx', 'runtime');
            $zip->close();
            $checksum = hash_file('sha256', $path);
            $this->assertSame('runtime', Release::runtime($path, $checksum));
            $this->expectException(RuntimeException::class);
            Release::runtime($path, str_repeat('0', 64));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_requires_an_exact_reported_version_not_a_substring(): void
    {
        Release::reportedVersion("Arix 1.2.3\n", '1.2.3');
        $this->addToAssertionCount(1);
        $this->expectException(RuntimeException::class);
        Release::reportedVersion('Arix 11.2.30', '1.2.3');
    }
}
