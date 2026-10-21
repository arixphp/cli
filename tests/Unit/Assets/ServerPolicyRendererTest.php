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
            $this->assertStringNotContainsString('+SymLinksIfOwnerMatch', $block);
        }
    }

    #[Test]
    public function it_checks_managed_blocks_without_modifying_surrounding_configuration(): void
    {
        $renderer = new ServerPolicyRenderer(new AssetPolicy());

        foreach (['apache', 'caddy', 'iis'] as $server) {
            $block = $renderer->render($server);
            $configuration = $this->configuration($server, $block);
            $this->assertTrue($renderer->matches($server, $configuration));
            $this->assertTrue($renderer->matches($server, "before\n{$configuration}\nafter"));
            $this->assertFalse($renderer->matches($server, "before\n{$block}\n{$block}\nafter"));
            $this->assertFalse($renderer->matches($server, 'No managed configuration'));
            $this->assertFalse($renderer->matches($server, str_replace('favicon', 'exposed', $configuration)));
        }
    }

    #[Test]
    public function it_refuses_a_configuration_without_the_mandatory_rules(): void
    {
        $renderer = new ServerPolicyRenderer(new AssetPolicy());

        foreach (['apache', 'caddy', 'iis'] as $server) {
            $configuration = $this->configuration($server, $renderer->render($server));

            $this->assertTrue($renderer->matches($server, $configuration));
            $this->assertSame([], $renderer->violations($server, $configuration));

            $without = $this->withoutMandatoryRule($server, $configuration);
            $this->assertNotSame([], $renderer->violations($server, $without));
            $this->assertFalse($renderer->matches($server, $without));

            $listing = $configuration . "\n" . $this->listingRule($server);
            $this->assertNotSame([], $renderer->violations($server, $listing));
            $this->assertFalse($renderer->matches($server, $listing));
        }
    }

    #[Test]
    public function it_refuses_a_configuration_that_enables_directory_listing(): void
    {
        $renderer = new ServerPolicyRenderer(new AssetPolicy());
        $apache = $this->configuration('apache', $renderer->render('apache'));

        $this->assertFalse($renderer->matches('apache', str_replace('Options -Indexes -MultiViews', 'Options +Indexes -MultiViews', $apache)));
        $this->assertFalse($renderer->matches('apache', str_replace('RewriteRule ^ index.php [L]', '', $apache)));
        $this->assertFalse($renderer->matches('apache', str_replace('DirectoryIndex index.php', '', $apache)));
    }

    #[Test]
    public function it_rejects_unknown_server_formats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ServerPolicyRenderer(new AssetPolicy())->render('nginx');
    }

    private function configuration(string $server, string $block): string
    {
        $surrounding = match ($server) {
            'apache' => "RewriteEngine On\nOptions -Indexes -MultiViews\nDirectoryIndex index.php\nRewriteRule ^ index.php [L]\n",
            'caddy' => "rewrite * /index.php\nphp_server {\n    file_server off\n}\n",
            'iis' => "<directoryBrowse enabled=\"false\" />\n<defaultDocument><files><clear /><add value=\"index.php\" /></files></defaultDocument>\n<allowDoubleEscaping>false</allowDoubleEscaping>\n<rule name=\"Arix front controller\" />\n<rule name=\"Public resource Allow header\" />\n",
            default => '',
        };

        return $surrounding . "\n" . $block . "\n";
    }

    private function withoutMandatoryRule(string $server, string $configuration): string
    {
        return match ($server) {
            'apache' => str_replace('DirectoryIndex index.php', '', $configuration),
            'caddy' => str_replace('rewrite * /index.php', '', $configuration),
            'iis' => str_replace('<directoryBrowse enabled="false" />', '', $configuration),
            default => $configuration,
        };
    }

    private function listingRule(string $server): string
    {
        return match ($server) {
            'apache' => 'Options +Indexes',
            'caddy' => 'browse',
            'iis' => '<directoryBrowse enabled="true" />',
            default => '',
        };
    }
}
