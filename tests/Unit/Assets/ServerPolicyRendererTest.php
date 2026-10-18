<?php

declare(strict_types=1);

namespace Tests\Unit\Assets;

use App\Assets\ServerPolicyRenderer;
use Arix\Assets\AssetPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServerPolicyRendererTest extends TestCase
{
    #[Test]
    public function it_renders_typed_roots_and_method_restrictions(): void
    {
        $renderer = new ServerPolicyRenderer(new AssetPolicy());

        foreach (['apache', 'caddy', 'iis'] as $server) {
            $block = $renderer->render($server);
            $this->assertStringContainsString('styles|scripts|fonts|media', $block);
            $this->assertStringContainsString('assets', $block);
            $this->assertStringContainsString('405', $block);
            $this->assertStringNotContainsString('assets/fonts/', $block);
        }

        $this->assertStringNotContainsString('{URL}', $renderer->render('iis'));
    }

    #[Test]
    public function it_protects_each_apache_public_directory(): void
    {
        $renderer = new ServerPolicyRenderer(new AssetPolicy());

        foreach (['styles', 'scripts', 'fonts', 'media'] as $directory) {
            $block = $renderer->renderDirectory($directory);
            $this->assertStringContainsString('-ExecCGI', $block);
            $this->assertStringContainsString('-FollowSymLinks', $block);
            $this->assertStringContainsString('SetHandler default-handler', $block);
            $this->assertStringContainsString('Require all denied', $block);
        }
    }

    #[Test]
    public function it_checks_managed_blocks_without_modifying_surrounding_configuration(): void
    {
        $renderer = new ServerPolicyRenderer(new AssetPolicy());

        foreach (['apache', 'caddy', 'iis'] as $server) {
            $block = $renderer->render($server);
            $this->assertTrue($renderer->matches($server, "before\n{$block}\nafter"));
            $this->assertTrue($renderer->matches($server, "before\n    " . str_replace("\n", "\n    ", $block) . "\nafter"));
            $this->assertFalse($renderer->matches($server, "before\n{$block}\n{$block}\nafter"));
            $this->assertFalse($renderer->matches($server, 'No managed configuration'));
            $this->assertFalse($renderer->matches($server, str_replace('favicon', 'exposed', $block)));
        }
    }

    #[Test]
    public function it_rejects_unknown_server_formats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServerPolicyRenderer(new AssetPolicy())->render('nginx');
    }
}
