<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\HttpFoundation\Request;
use Arix\Routing\UrlGenerator;
use Arix\Support\AssetVersioner;
use Arix\Views\ViewServices;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;

class AssetVersionerTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
        $this->fixture->write('styles/app.css', 'abc');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_versions_content_and_preserves_existing_asset_urls(): void
    {
        $urls = new UrlGenerator('/site');
        $versioner = new AssetVersioner($this->fixture->root, $urls);
        $this->assertSame('/site/styles/app.css?v=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $versioner->asset('styles/app.css'));
        $this->assertSame('/site/styles/app.css', $urls->asset('styles/app.css'));
        $this->assertSame($versioner->asset('styles/app.css'), $versioner->asset('/styles/%61pp.css'));
    }

    #[Test]
    #[DataProvider('allowedAssets')]
    public function it_versions_each_allowed_public_asset(string $path): void
    {
        $this->fixture->write($path, 'abc');

        $url = new AssetVersioner($this->fixture->root, new UrlGenerator())->asset($path);

        $this->assertSame("/{$path}?v=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad", $url);
    }

    public static function allowedAssets(): array
    {
        return [
            ['styles/nested/app.CSS'],
            ['scripts/app.js'],
            ['assets/image.avif'],
            ['assets/photo.JPEG'],
            ['assets/media/movie.ogv'],
            ['assets/fonts/inter.woff2'],
            ['assets/fonts/nested/inter.TTF'],
            ['assets/fonts-archive/image.png'],
        ];
    }

    #[Test]
    public function it_does_not_reuse_hashes_between_requests_even_with_identical_stat_metadata(): void
    {
        $first = new AssetVersioner($this->fixture->root, new UrlGenerator())->asset('styles/app.css');
        $mtime = filemtime($this->fixture->path('styles/app.css'));
        $this->fixture->write('styles/app.css', 'def');
        touch($this->fixture->path('styles/app.css'), $mtime);
        $second = new AssetVersioner($this->fixture->root, new UrlGenerator())->asset('styles/app.css');
        $this->assertNotSame($first, $second);
    }

    #[Test]
    #[DataProvider('unsafeAssets')]
    public function it_rejects_private_missing_or_unsafe_assets(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssetVersioner($this->fixture->root, new UrlGenerator())->asset($path);
    }

    public static function unsafeAssets(): array
    {
        return [[''], ['//styles/app.css'], ['styles/missing.css'], ['styles/app.css?v=1'], ['../styles/app.css'], ['styles/%2e%2e/app.css'], ['styles/a%2fapp.css'], ['styles/file.php'], ['core/autoload.php'], ['assets/fonts/image.png'], ['assets/image.svg']];
    }

    #[Test]
    public function it_rejects_linked_assets(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Creating links requires Windows privileges.');
        }

        symlink($this->fixture->path('styles/app.css'), $this->fixture->path('styles/link.css'));
        $this->expectException(InvalidArgumentException::class);
        new AssetVersioner($this->fixture->root, new UrlGenerator())->asset('styles/link.css');
    }

    #[Test]
    public function it_loads_the_helper_without_composer(): void
    {
        $loader = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        $process = new Process([PHP_BINARY, '-r', 'require $argv[1]; $service = new Arix\\Support\\AssetVersioner($argv[2], new Arix\\Routing\\UrlGenerator()); echo $service->asset("styles/app.css");', $loader, $this->fixture->root], $this->fixture->root, ['XDEBUG_MODE' => 'off']);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('/styles/app.css?v=ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', $process->getOutput());
    }

    #[Test]
    public function it_registers_the_helper_in_request_scoped_view_services(): void
    {
        $this->fixture->write('app/views/page.php', '<?php');
        $this->fixture->write('app/lang/en.php', '<?php return [];');
        $container = new Container();
        ViewServices::register($container, $this->fixture->root, Request::create('/'), ['app' => ['locales' => ['en'], 'default_locale' => 'en'], 'cache' => [], 'views' => $this->fixture->path('app/views'), 'lang' => $this->fixture->path('app/lang')]);
        $url = ContainerContext::run($container, static fn (): string => versioned_asset('styles/app.css'));
        $this->assertStringContainsString('?v=ba7816bf', $url);
        $this->expectException(LogicException::class);
        versioned_asset('styles/app.css');
    }
}
