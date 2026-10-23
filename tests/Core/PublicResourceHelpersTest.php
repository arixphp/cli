<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Assets\AssetServices;
use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Routing\UrlGenerator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewApplicationFixture;

class PublicResourceHelpersTest extends TestCase
{
    #[Test]
    public function it_requires_an_active_request_scope(): void
    {
        $this->expectException(LogicException::class);
        media('logo.webp');
    }

    #[Test]
    public function it_requires_a_real_file_when_media_versioning_is_requested(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $container = new Container();

            $generator = UrlGenerator::fromApplicationUrl('https://myapp.com');
            AssetServices::register($container, $fixture->root, $generator, $generator);

            $this->expectException(InvalidArgumentException::class);

            ContainerContext::run($container, static fn (): string => media('missing.webp', versioned: true));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_does_not_reuse_hashes_between_requests(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $file = $fixture->write('media/logo.webp', 'abc');
            $fixture->write('fonts/inter.woff2', 'abc');
            $first = new Container();

            $generator = UrlGenerator::fromApplicationUrl('https://myapp.com');
            AssetServices::register($first, $fixture->root, $generator, $generator);
            $urls = ContainerContext::run($first, static fn (): array => [media('logo.webp', versioned: true), font('inter.woff2', versioned: true)]);
            $hash = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

            $this->assertSame(["https://myapp.com/media/logo.webp?v={$hash}", "https://myapp.com/fonts/inter.woff2?v={$hash}"], $urls);

            $mtime = filemtime($file);

            file_put_contents($file, 'def');
            touch($file, $mtime);

            $second = new Container();

            AssetServices::register($second, $fixture->root, $generator, $generator);

            $url = ContainerContext::run($second, static fn (): string => media('logo.webp', versioned: true));

            $this->assertNotSame($urls[0], $url);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_versions_styles_and_scripts_by_content(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('styles/app.css', 'abc');
            $fixture->write('scripts/app.js', 'abc');
            $container = new Container();

            $generator = UrlGenerator::fromApplicationUrl('https://myapp.com/site');
            AssetServices::register($container, $fixture->root, $generator, $generator);

            $urls = ContainerContext::run($container, static fn (): array => [style('app.css'), script('app.js')]);

            $hash = 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad';

            $this->assertSame("https://myapp.com/site/styles/app.css?v={$hash}", $urls[0]);
            $this->assertSame("https://myapp.com/site/scripts/app.js?v={$hash}", $urls[1]);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_versions_fonts_and_media_only_when_requested(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $container = new Container();
            $generator = UrlGenerator::fromApplicationUrl('https://myapp.com/site');
            AssetServices::register($container, $fixture->root, $generator, $generator);

            $urls = ContainerContext::run($container, static fn (): array => [font('missing.woff2'), media('missing.webp')]);

            $this->assertSame(['https://myapp.com/site/fonts/missing.woff2', 'https://myapp.com/site/media/missing.webp'], $urls);
        } finally {
            $fixture->cleanup();
        }
    }
}
