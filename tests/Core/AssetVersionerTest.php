<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Assets\AssetLocator;
use Arix\Assets\AssetPolicy;
use Arix\Assets\AssetVersioner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;

class AssetVersionerTest extends TestCase
{
    #[Test]
    public function it_loads_the_new_namespace_with_composer_without_the_old_class(): void
    {
        $root = dirname(__DIR__, 2);
        $program = <<<'PHP'
require $argv[1] . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (! class_exists('Arix\\Assets\\AssetVersioner')) { throw new RuntimeException('Asset versioner missing.'); }
if (class_exists('Arix\\Support\\AssetVersioner')) { throw new RuntimeException('Legacy class remains.'); }
PHP;
        $process = new Process([PHP_BINARY, '-r', $program, $root], $root, ['XDEBUG_MODE' => 'off']);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    #[Test]
    public function it_hashes_canonical_public_files(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('styles/app.css', 'abc');
            $versioner = new AssetVersioner(new AssetLocator($fixture->root, new AssetPolicy()));

            $this->assertSame('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $versioner->hash('styles/app.css'));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_rejects_missing_public_files(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $this->expectException(InvalidArgumentException::class);
            (new AssetVersioner(new AssetLocator($fixture->root, new AssetPolicy())))->hash('styles/missing.css');
        } finally {
            $fixture->cleanup();
        }
    }
}
