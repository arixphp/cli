<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\PlatformRequirements;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class PlatformRequirementsTest extends TestCase
{
    #[Test]
    public function it_verifies_available_installer_capabilities(): void
    {
        new PlatformRequirements()->verify();
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_reports_missing_extensions_without_composer_bootstrap(): void
    {
        $file = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Installation' . DIRECTORY_SEPARATOR . 'PlatformRequirements.php';
        $script = 'namespace App\\Installation; function extension_loaded(string $extension): bool { return false; } require ' . var_export($file, true) . '; try { (new PlatformRequirements())->verify(); } catch (\\RuntimeException $e) { fwrite(STDERR, $e->getMessage()); exit(1); }';
        $process = new Process([PHP_BINARY, '-n', '-r', $script]);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('ext-curl', $process->getErrorOutput());
        $this->assertStringContainsString('ext-zip', $process->getErrorOutput());
    }
}
