<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Assets\AssetLocator;
use Arix\Assets\AssetPolicy;
use Arix\Support\AssetVersioner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewApplicationFixture;

class AssetVersionerTest extends TestCase
{
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
