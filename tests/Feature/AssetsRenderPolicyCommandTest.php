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

            file_put_contents($file, new ServerPolicyRenderer(new AssetPolicy())->render('caddy'));

            $this->artisan('assets:policy', ['server' => 'caddy', '--check' => $file])->assertExitCode(0);
        } finally {
            $fixture->cleanup();
        }
    }
}
