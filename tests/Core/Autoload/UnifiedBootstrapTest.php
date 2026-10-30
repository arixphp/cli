<?php

declare(strict_types=1);

namespace Tests\Core\Autoload;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\TempTree;

class UnifiedBootstrapTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        $this->tree = new TempTree();
        (new Filesystem())->mirror(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'core', $this->tree->path('core'));
        $this->tree->applicationManifest();
    }

    protected function tearDown(): void
    {
        $this->tree->cleanup();
    }

    #[Test]
    public function it_registers_one_loader_for_framework_application_and_custom_namespaces(): void
    {
        $this->tree->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/","Arix\\\\":"core/","Domain\\\\":"domain/"},"files":["helpers.php"]}}');
        $this->tree->write('app/Item.php', '<?php namespace App; class Item {}');
        $this->tree->write('domain/Value.php', '<?php namespace Domain; class Value {}');
        $this->tree->write('helpers.php', '<?php $GLOBALS["unified"] = new \\Domain\\Value();');
        $this->runProgram(<<<'PHP'
$loader = require $argv[1] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
if (count(spl_autoload_functions()) !== 1) { throw new RuntimeException('Expected one loader.'); }
if (! class_exists('App\\Item')) { throw new RuntimeException('Application mapping was not loaded.'); }
if (! class_exists('Arix\\Config\\Repository')) { throw new RuntimeException('Framework mapping was not loaded.'); }
if (! $GLOBALS['unified'] instanceof \Domain\Value) { throw new RuntimeException('Files ran before mappings.'); }
$loader->register();
if (count(spl_autoload_functions()) !== 1) { throw new RuntimeException('Repeated registration added a loader.'); }
if (class_exists('Composer\\Autoload\\ClassLoader', false)) { throw new RuntimeException('Composer was loaded.'); }
if (class_exists('ClassLoader', false) || class_exists('AutoloadConfiguration', false)) { throw new RuntimeException('Global autoload aliases remain.'); }
echo 'unified';
PHP, 'unified');
    }

    #[Test]
    public function it_rejects_missing_manifests_without_registering_a_fallback(): void
    {
        unlink($this->tree->path('.arix.json'));
        $this->runProgram(<<<'PHP'
try {
    require $argv[1] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
    throw new LogicException('Missing manifest accepted.');
} catch (RuntimeException $exception) {
    if (count(spl_autoload_functions()) !== 0) { throw new LogicException('A fallback loader remains.'); }
    echo 'rejected';
}
PHP, 'rejected');
    }

    #[Test]
    public function it_has_no_implicit_framework_mapping_outside_the_manifest(): void
    {
        $this->tree->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
        $this->runProgram(<<<'PHP'
require $argv[1] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
if (class_exists('Arix\\Config\\Repository')) { throw new RuntimeException('Implicit framework fallback remains.'); }
if (count(spl_autoload_functions()) !== 1) { throw new RuntimeException('Expected one loader.'); }
echo 'declarative';
PHP, 'declarative');
    }

    #[Test]
    public function it_loads_asset_versioner_from_the_assets_namespace_without_composer(): void
    {
        $this->tree->write('styles/app.css', 'abc');
        $this->runProgram(<<<'PHP'
require $argv[1] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
$locator = new \Arix\Assets\AssetLocator($argv[1], new \Arix\Assets\AssetPolicy());
$versioner = new \Arix\Assets\AssetVersioner($locator);
if ($versioner->hash('styles/app.css') !== hash('sha256', 'abc')) { throw new RuntimeException('Asset hash mismatch.'); }
if (class_exists('Arix\\Support\\AssetVersioner')) { throw new RuntimeException('Legacy class remains.'); }
if (class_exists('Composer\\Autoload\\ClassLoader', false)) { throw new RuntimeException('Composer was loaded.'); }
echo 'assets';
PHP, 'assets');
    }

    #[Test]
    public function it_does_not_enable_development_mappings_from_debug_mode(): void
    {
        $this->tree->write('.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"Arix\\\\":"core/"}},"autoload-dev":{"psr-4":{"Checks\\\\":"checks/"}}}');
        $this->tree->write('checks/Probe.php', '<?php declare(strict_types=1); namespace Checks; class Probe {}');
        $this->runProgram(<<<'PHP'
$_ENV['APP_DEBUG'] = 'true';
$loader = require $argv[1] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
if ($loader->development || class_exists('Checks\\Probe')) { throw new RuntimeException('Debug enabled development'); }
echo 'production';
PHP, 'production');
    }

    private function runProgram(string $program, string $expected): void
    {
        $process = new Process([PHP_BINARY, '-r', $program, $this->tree->root]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame($expected, $process->getOutput());
    }
}
