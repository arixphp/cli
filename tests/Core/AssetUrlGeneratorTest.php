<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Assets\AssetLocator;
use Arix\Assets\AssetPolicy;
use Arix\Assets\AssetServices;
use Arix\Assets\AssetType;
use Arix\Assets\AssetUrlGenerator;
use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\Routing\UrlGenerator;
use Arix\Views\ViewServices;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewApplicationFixture;

class AssetUrlGeneratorTest extends TestCase
{
    #[Test]
    public function it_builds_typed_missing_resource_urls_without_requiring_views(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $container = new Container();
            $urls = new UrlGenerator('/site');

            AssetServices::register($container, $fixture->root, $urls);

            $this->assertSame('/site/media/logo.webp', $container->get(AssetUrlGenerator::class)->to(AssetType::Media, 'logo.webp', false));
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

            AssetServices::register($container, $fixture->root, new UrlGenerator('/site'));

            $assets = $container->get(AssetUrlGenerator::class);

            ViewServices::register($container, $fixture->root, Request::create('/'), [
                'app' => ['locales' => ['en'], 'default_locale' => 'en', 'base_path' => '/site'],
                'cache' => [], 'views' => $fixture->path('app/views'), 'lang' => $fixture->path('app/lang'),
            ]);

            $this->assertSame($assets, $container->get(AssetUrlGenerator::class));
        } finally {
            $fixture->cleanup();
        }
    }
}
