<?php

declare(strict_types=1);

use App\Testing\BundledRuntime;
use App\Testing\PestRunner;

use function in_array as inArray;

require __DIR__ . DIRECTORY_SEPARATOR . 'PestRunner.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'BundledRuntime.php';

try {
    if (($_SERVER['argc'] ?? 0) !== 4 || ! inArray($_SERVER['argv'][3], ['always', 'never'], true)) {
        throw new RuntimeException('Invalid internal test worker arguments.');
    }

    $distribution = BundledRuntime::prepare(dirname(__DIR__, 2));
    $status = PestRunner::run($_SERVER['argv'][2], $distribution, $_SERVER['argv'][3] === 'always');
} catch (Throwable $exception) {
    fwrite(STDERR, "Test runner: {$exception->getMessage()}\n");
    $status = 2;
}
