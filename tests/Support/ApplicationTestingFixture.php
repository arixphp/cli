<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

class ApplicationTestingFixture
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix tests ' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->write('index.php', '<?php');
        $this->write('app/bootstrap.php', '<?php');
        $this->write('core/autoload.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Arix { class ApplicationMarker {} }
namespace {
    function view(): string { return 'application-view'; }
    function trans(): string { return 'application-trans'; }
    function config(): string { return 'application-config'; }
    function env(): string { return 'application-env'; }
}
PHP);
        $this->write('tests/autoload.php', '<?php require_once dirname(__DIR__) . "/core/autoload.php";');
        $this->write('phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/autoload.php" recordTestRunHistory="false">
    <testsuites><testsuite name="Application"><directory suffix="Test.php">tests</directory></testsuite></testsuites>
</phpunit>
XML);
        $this->write('tests/Pest.php', '<?php expect()->extend("toBeApplicationValue", fn () => $this->toBe("application"));');
        $this->write('tests/ExampleTest.php', '<?php test("application closure", fn () => expect("application")->toBeApplicationValue());');
    }

    public function path(string $relative): string
    {

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function write(string $relative, string $content): void
    {
        $file = $this->path($relative);

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $content);
    }

    public function worker(string|null $entry = null): Process
    {
        $entry ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'arix';
        $process = new Process([PHP_BINARY, $entry, '--arix-tests-worker', $this->root, 'never'], $this->root, ['XDEBUG_MODE' => 'off']);
        $process->run();

        return $process;
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->root);
    }
}
