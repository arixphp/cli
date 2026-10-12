<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CoreInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\CoreUpdateFixture;

class CoreUpdateEntryPointTest extends TestCase
{
    #[Test]
    public function it_updates_from_the_real_entry_point_and_loads_the_installed_core_without_composer(): void
    {
        $fixture = new CoreUpdateFixture();
        $project = dirname(__DIR__, 4);

        try {
            $application = $fixture->installed();
            $process = new Process([
                PHP_BINARY, $project . DIRECTORY_SEPARATOR . 'arix', 'core:update',
                '--path=' . $application, '--yes', '--no-interaction',
            ], $fixture->root, ['XDEBUG_MODE' => 'off']);
            $process->setTimeout(60);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            $this->assertStringContainsString('Backup retained', $process->getOutput());
            $this->assertSame(CoreDistribution::bundled($project)->manifest['id'], new CoreInspector()->installedManifest($application . DIRECTORY_SEPARATOR . 'core')['id']);

            $standalone = new Process([
                PHP_BINARY, '-r',
                'require $argv[1]; echo class_exists("Arix\\\\Http\\\\Kernel") ? "standalone" : "missing";',
                $application . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php',
            ], $fixture->root, ['XDEBUG_MODE' => 'off']);
            $standalone->run();
            $this->assertSame(0, $standalone->getExitCode(), $standalone->getErrorOutput());
            $this->assertSame('standalone', $standalone->getOutput());
        } finally {
            $fixture->cleanup();
        }
    }
}
