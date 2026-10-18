<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Assets\AssetPolicy;
use Arix\Assets\AssetType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewApplicationFixture;

class AssetPolicyTest extends TestCase
{
    #[Test]
    public function it_rejects_encoded_full_root_prefixes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssetPolicy()->resolve(AssetType::Media, '%6dedia/logo.webp');
    }

    #[Test]
    public function it_does_not_decode_canonical_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssetPolicy()->normalize('scripts/%61pp.js');
    }

    #[Test]
    public function it_rejects_font_extensions_in_media_policy(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $metadata = json_decode(file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Assets' . DIRECTORY_SEPARATOR . 'public-policy.json'), true);
            $metadata['directories']['media'][] = 'woff2';
            $file = $fixture->write('policy.json', json_encode($metadata));
            $this->expectException(InvalidArgumentException::class);
            new AssetPolicy($file);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_normalizes_only_public_asset_paths(): void
    {
        $policy = new AssetPolicy();

        $this->assertSame('scripts/arix/index.js', $policy->resolve(AssetType::Script, 'arix/%69ndex.js'));
        $this->assertSame('styles/arix/base.CSS', $policy->normalize('styles/arix/base.CSS'));
        $this->assertSame('favicon.ico', $policy->normalize('favicon.ico'));
        $this->assertSame('fonts/inter.woff2', $policy->normalize('fonts/inter.woff2'));
    }

    #[Test]
    #[DataProvider('unsafePaths')]
    public function it_rejects_private_ambiguous_and_executable_paths(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AssetPolicy()->normalize($path);
    }

    public static function unsafePaths(): array
    {

        return array_map(static fn (string $path): array => [$path], [
            '', '/', '//scripts/app.js', 'core/autoload.php', 'CoRe/autoload.php',
            '../scripts/app.js', 'scripts/../app.js', 'scripts/%2e%2e/app.js',
            'scripts/%252e%252e/app.js', 'scripts/a%2fb.js', 'scripts/a%5cb.js',
            'scripts/a%00.js', 'scripts/%zz.js', 'scripts//app.js', 'scripts/app.js?v=1',
            'scripts/app.js#x', 'scripts/app.php', 'scripts/app.phtml', 'scripts/app.php.js',
            'scripts/app.js.map', '.git/config', 'public-policy.json', 'robots.txt',
            'frankenphp', 'assets/fonts/image.png', 'assets/image.svg',
            'fonts/image.png', 'media/image.svg', 'C:\\scripts\\app.js', '\\\\host\\file',
            'php://filter/resource=scripts/app.js', '.scripts-backup-x/arix.js',
        ]);
    }

    #[Test]
    #[DataProvider('invalidPolicies')]
    public function it_fails_closed_on_invalid_policy_metadata(string $json): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $file = $fixture->write('policy.json', $json);
            $this->expectException(InvalidArgumentException::class);
            new AssetPolicy($file);
        } finally {
            $fixture->cleanup();
        }
    }

    public static function invalidPolicies(): array
    {

        return [['{'], ['{}'], ['{"format":2,"directories":{"scripts":["php"]},"root_files":[]}']];
    }
}
