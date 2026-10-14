<?php

declare(strict_types=1);

namespace Arix\Release;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

use function in_array;

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'Release.php';

try {
    $source = $argv[1] ?? '';
    $target = $argv[2] ?? '';
    $version = $argv[3] ?? '';
    $destination = $argv[4] ?? '';
    $extension = $target === 'windows-x64' ? 'zip' : 'tar.gz';
    $archive = "arix-{$version}-{$target}.{$extension}";

    if (! in_array($archive, Release::archives("v{$version}"), true) || ! is_file($source) || is_link($source) || $destination === '') {
        throw new RuntimeException('Expected executable, supported target, SemVer version and destination.');
    }

    if (! is_dir($destination) && ! mkdir($destination, 0755, true)) {
        throw new RuntimeException('Cannot create distribution directory.');
    }

    $destination = realpath($destination);
    $name = $target === 'windows-x64' ? 'arix.exe' : 'arix';
    $stage = $destination . DIRECTORY_SEPARATOR . ".verify-{$target}";

    if (file_exists($stage) || ! mkdir($stage, 0755)) {
        throw new RuntimeException('Verification directory already exists or cannot be created.');
    }

    $executable = $stage . DIRECTORY_SEPARATOR . $name;

    if (! copy($source, $executable)) {
        throw new RuntimeException('Cannot stage executable.');
    }

    chmod($executable, 0755);
    $path = $destination . DIRECTORY_SEPARATOR . $archive;

    if (file_exists($path)) {
        throw new RuntimeException('Distribution archive already exists.');
    }

    if ($extension === 'zip') {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Cannot create Windows distribution archive.');
        }

        $zip->addFile($executable, $name);
        $zip->close();
        unlink($executable);
        $zip->open($path);
        $zip->extractTo($stage, [$name]);
        $zip->close();
    } else {
        (new Process(['tar', '-czf', $path, '-C', $stage, $name]))->mustRun();
        unlink($executable);
        (new Process(['tar', '-xzf', $path, '-C', $stage]))->mustRun();
    }

    if (! is_file($executable) || ! hash_equals(hash_file('sha256', $source), hash_file('sha256', $executable))) {
        throw new RuntimeException('Distribution archive changed the executable.');
    }

    if (PHP_OS_FAMILY !== 'Windows' && $extension !== 'zip' && ! is_executable($executable)) {
        throw new RuntimeException('Distribution archive lost executable permissions.');
    }

    fwrite(STDOUT, "{$executable}\n");
    $output = getenv('GITHUB_OUTPUT');

    if ($output !== false && file_put_contents($output, "binary={$executable}\n", FILE_APPEND) === false) {
        throw new RuntimeException('Cannot record extracted executable path.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
