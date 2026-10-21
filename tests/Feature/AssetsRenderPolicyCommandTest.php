<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Assets\ServerPolicyRenderer;
use Arix\Assets\AssetPolicy;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\NewApplicationFixture;
use Tests\TestCase;

class AssetsRenderPolicyCommandTest extends TestCase
{
    #[Test]
    public function it_reports_drift_without_writing_files(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $file = $fixture->write('Caddyfile', 'custom configuration');

            $this->artisan('assets:policy', ['server' => 'caddy', '--check' => $file])->assertExitCode(1);
            $this->assertSame('custom configuration', file_get_contents($file));

            file_put_contents($file, $this->configuration('caddy'));

            $this->artisan('assets:policy', ['server' => 'caddy', '--check' => $file])->assertExitCode(0);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_reports_a_configuration_without_the_mandatory_rules(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $file = $fixture->write('Caddyfile', $this->configuration('caddy', 'rewrite * /index.php' . "\n"));

            $this->artisan('assets:policy', ['server' => 'caddy', '--check' => $file])->assertExitCode(1);
        } finally {
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_verifies_the_policy_of_a_public_resource_directory(): void
    {
        $fixture = new NewApplicationFixture();

        try {
            $directory = $fixture->path('styles');
            mkdir($directory, 0755, true);
            file_put_contents($directory . DIRECTORY_SEPARATOR . '.htaccess', 'outdated');

            $this->artisan('assets:policy', ['server' => 'apache', '--directory' => $directory])->assertExitCode(1);

            file_put_contents($directory . DIRECTORY_SEPARATOR . '.htaccess', (new ServerPolicyRenderer(new AssetPolicy()))->renderDirectory('styles'));

            $this->artisan('assets:policy', ['server' => 'apache', '--directory' => $directory])->assertExitCode(0);
        } finally {
            $fixture->cleanup();
        }
    }

    private function configuration(string $server, string $surrounding = ''): string
    {
        $block = (new ServerPolicyRenderer(new AssetPolicy()))->render($server);
        $surrounding = $surrounding === '' ? $this->surrounding($server) : $surrounding;

        return $surrounding . "\n" . $block . "\n";
    }

    private function surrounding(string $server): string
    {
        return match ($server) {
            'apache' => "RewriteEngine On\nOptions -Indexes -MultiViews\nDirectoryIndex index.php\nRewriteRule ^ index.php [L]\n",
            'caddy' => "rewrite * /index.php\nphp_server {\n    file_server off\n}\n",
            'iis' => "<directoryBrowse enabled=\"false\" />\n<defaultDocument><files><clear /><add value=\"index.php\" /></files></defaultDocument>\n<allowDoubleEscaping>false</allowDoubleEscaping>\n<rule name=\"Arix front controller\" />\n<rule name=\"Public resource Allow header\" />\n",
            default => '',
        };
    }
}
