<?php

declare(strict_types=1);

use App\Formatting\PintRuntime;

use function count as argumentCount;
use function in_array as inArray;
use function is_int as isInt;

require __DIR__ . DIRECTORY_SEPARATOR . 'PintRuntime.php';

try {
    if (($_SERVER['argc'] ?? 0) !== 5 || ! inArray($_SERVER['argv'][4], ['check', 'fix'], true)) {
        throw new RuntimeException('Invalid internal format worker arguments.');
    }

    $application = realpath($_SERVER['argv'][2]);
    $config = $_SERVER['argv'][3];
    $test = $_SERVER['argv'][4] === 'check';

    if ($application === false || ! is_dir($application) || ! chdir($application)) {
        throw new RuntimeException('Cannot open application format directory.');
    }

    if (! is_file($config) || ! is_readable($config)) {
        throw new RuntimeException('Format configuration is unavailable.');
    }

    $pint = PintRuntime::prepare(dirname(__DIR__, 2));
    $argv = [$pint, "--config={$config}", '--no-ansi'];

    if ($test) {
        $argv[] = '--test';
    }

    $argc = argumentCount($argv);
    $_SERVER['argv'] = $argv;
    $_SERVER['argc'] = $argc;

    // The outer Box archive already checks all production requirements, including Pint.
    // Its checker shares Composer classes with Pint's checker; do not load it twice.
    if (Phar::running(false) !== '') {
        $_SERVER['BOX_REQUIREMENT_CHECKER'] = false;
    }

    $result = require $pint;
    $status = isInt($result) ? $result : 0;
} catch (Throwable $exception) {
    fwrite(STDERR, "Format runner: {$exception->getMessage()}\n");
    $status = 2;
}
