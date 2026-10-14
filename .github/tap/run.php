<?php

declare(strict_types=1);

use Arix\Release\Release;
use Arix\Tap\Formula;

require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'Release.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'Formula.php';

try {
    $directory = $argv[1] ?? '';
    $version = Release::version($argv[2] ?? '');
    $dist = $argv[3] ?? '';
    $checksums = [];

    foreach (Release::archives($version) as $name) {
        if (str_ends_with($name, '.zip')) {
            continue;
        }

        $path = $dist . DIRECTORY_SEPARATOR . $name;

        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException("Missing distribution archive: {$name}");
        }

        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException("Cannot hash distribution archive: {$name}");
        }

        $checksums[$name] = $hash;
    }

    Formula::update($directory, $version, $checksums);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
