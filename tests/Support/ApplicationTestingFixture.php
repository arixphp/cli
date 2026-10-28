<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

class ApplicationTestingFixture extends TestApplication
{
    public function __construct()
    {
        parent::__construct();
        unlink($this->path('tests/SmokeTest.php'));
        $this->write('core/functions.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace {
    function view(): string { return 'application-view'; }
    function trans(): string { return 'application-trans'; }
    function config(): string { return 'application-config'; }
    function env(): string { return 'application-env'; }
}
PHP);
        $this->write('core/ApplicationMarker.php', '<?php declare(strict_types=1); namespace Arix; class ApplicationMarker {}');
        $this->write('tests/Pest.php', '<?php expect()->extend("toBeApplicationValue", fn () => $this->toBe("application"));');
        $this->write('tests/ExampleTest.php', '<?php test("application closure", fn () => expect("application")->toBeApplicationValue());');
    }

    public function worker(string|null $entry = null): Process
    {
        $entry ??= dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'arix';
        $process = new Process([PHP_BINARY, $entry, '--arix-tests-worker', $this->root, 'never'], $this->root, ['XDEBUG_MODE' => 'off']);
        $process->run();

        return $process;
    }
}
