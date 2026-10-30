<?php

declare(strict_types=1);

namespace Tests\Core;

use ArgumentCountError;
use Arix\Assets\AssetLocator;
use Arix\Assets\AssetPolicy;
use Arix\Assets\AssetServices;
use Arix\Assets\AssetType;
use Arix\Assets\AssetUrlGenerator;
use Arix\Container\Container;
use Arix\Routing\ApplicationUrl;
use Arix\Routing\UrlGenerator;
use Arix\Views\ViewServices;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewApplicationFixture;

class AssetUrlGeneratorTest extends TestCase
{
    #[Test]
    public function it_requires_an_explicit_asset_url_generator(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $urls = UrlGenerator::fromApplicationUrl('https://myapp.com');
            $this->expectException(ArgumentCountError::class);
            AssetServices::register(new Container(), $fixture->root, $urls);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_does_not_create_url_or_asset_services_when_registering_views(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('app/views/page.php', '<?php');
            $fixture->write('app/lang/en.php', '<?php return [];');
            $container = new Container();
            ViewServices::register($container, $fixture->root, [
                'app' => ['locales' => ['en'], 'default_locale' => 'en'],
                'cache' => [], 'views' => $fixture->path('app/views'), 'lang' => $fixture->path('app/lang'),
            ]);
            $this->assertFalse($container->has(UrlGenerator::class));
            $this->assertFalse($container->has(AssetUrlGenerator::class));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_separates_script_bearing_route_urls_from_asset_urls(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('styles/app.css', 'body{}');
            $container = new Container();
            AssetServices::register($container, $fixture->root,
                new UrlGenerator(ApplicationUrl::fromString('https://myapp.com/sub/index.php'), '/about'),
                new UrlGenerator(ApplicationUrl::fromString('https://myapp.com/sub'), '/about'));
            $this->assertSame('https://myapp.com/sub/index.php/about', $container->get(UrlGenerator::class)->to('/about'));
            $this->assertSame('https://myapp.com/sub/styles/app.css', $container->get(AssetUrlGenerator::class)->to(AssetType::Style, 'app.css', false));
            $this->assertStringStartsWith('https://myapp.com/sub/styles/app.css?v=', $container->get(AssetUrlGenerator::class)->to(AssetType::Style, 'app.css', true));
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_builds_typed_missing_resource_urls_without_requiring_views(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $container = new Container();
            $urls = UrlGenerator::fromApplicationUrl('https://myapp.com/site');

            AssetServices::register($container, $fixture->root, $urls, $urls);

            $this->assertSame('https://myapp.com/site/media/logo.webp', $container->get(AssetUrlGenerator::class)->to(AssetType::Media, 'logo.webp', false));
            $this->expectException(InvalidArgumentException::class);

            $container->get(AssetUrlGenerator::class)->to(AssetType::Script, 'scripts/app.js', false);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_rejects_links_and_inexact_casing_when_locating_files(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $file = $fixture->write('scripts/arix/index.js', 'export const value = 1;');
            $locator = new AssetLocator($fixture->root, new AssetPolicy());

            $this->assertSame($file, $locator->locate('scripts/arix/index.js'));
            $this->expectException(InvalidArgumentException::class);

            $locator->locate('scripts/arix/Index.js');
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_preserves_asset_services_when_registering_views(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $fixture->write('app/views/page.php', '<?php');
            $fixture->write('app/lang/en.php', '<?php return [];');

            $container = new Container();

            $urls = UrlGenerator::fromApplicationUrl('https://myapp.com/site');
            AssetServices::register($container, $fixture->root, $urls, $urls);

            $assets = $container->get(AssetUrlGenerator::class);

            ViewServices::register($container, $fixture->root, [
                'app' => ['locales' => ['en'], 'default_locale' => 'en', 'base_path' => '/site'],
                'cache' => [], 'views' => $fixture->path('app/views'), 'lang' => $fixture->path('app/lang'),
            ]);

            $this->assertSame($assets, $container->get(AssetUrlGenerator::class));
        } finally {
            $fixture->cleanup();
        }
    }
}
