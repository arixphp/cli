<?php

declare(strict_types=1);

use Arix\Release\Release;
use PHPacker\PHPacker\Support\AssetManager;
use PHPacker\PHPacker\Support\Combine;
use PHPacker\PHPacker\Support\Config\ConfigRepository;

require __DIR__ . DIRECTORY_SEPARATOR . 'Release.php';

try {
    $platform = $argv[1] ?? '';
    $architecture = $argv[2] ?? '';
    $source = realpath($argv[3] ?? '');
    $destination = $argv[4] ?? '';
    $manifest = json_decode(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'runtimes.json'), true, flags: JSON_THROW_ON_ERROR);
    $target = $manifest['targets']["{$platform}-{$architecture}"] ?? null;

    if ($target === null || $source === false || $destination === '') {
        throw new RuntimeException('Expected platform, architecture, existing PHAR and destination.');
    }

    // Keep PHPacker state inside the release workspace, never in the user's home.
    $home = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'phpacker' . DIRECTORY_SEPARATOR . 'runtime';
    putenv("HOME={$home}");
    putenv("LOCALAPPDATA={$home}");
    putenv("XDG_DATA_HOME={$home}");
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'phpacker' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    $manager = new AssetManager($manifest['repository']);
    $zipPath = getenv('ARIX_RUNTIME_ARCHIVE') ?: $manager->baseDir() . DIRECTORY_SEPARATOR . "{$platform}-{$architecture}.zip";

    if (! getenv('ARIX_RUNTIME_ARCHIVE')) {
        $url = "https://raw.githubusercontent.com/{$manifest['repository']}/{$manifest['commit']}/bin/{$target['path']}/php-{$manifest['php']}.zip";
        $download = file_get_contents($url, context: stream_context_create(['http' => ['timeout' => 120]]));

        if ($download === false || file_put_contents($zipPath, $download) === false) {
            throw new RuntimeException('Cannot download embedded PHP runtime.');
        }
    }

    $micro = Release::runtime($zipPath, $target['sha256']);
    $directory = $manager->baseDir() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target['path']) . DIRECTORY_SEPARATOR . $manifest['php'];

    if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
        throw new RuntimeException('Cannot prepare embedded PHP runtime.');
    }

    if (file_put_contents($directory . DIRECTORY_SEPARATOR . 'micro.sfx', $micro) === false) {
        throw new RuntimeException('Cannot write verified embedded PHP runtime.');
    }

    // Use PHPacker's packager directly: its CLI otherwise refreshes from "latest".
    Combine::build($platform, $architecture, new ConfigRepository([
        'php' => $manifest['php'], 'repository' => $manifest['repository'], 'dest' => $destination, 'src' => $source, 'ini' => [],
    ]));
    $suffix = $platform === 'windows' ? '.exe' : '';
    $binary = realpath($destination . DIRECTORY_SEPARATOR . $platform . DIRECTORY_SEPARATOR . "{$platform}-{$architecture}{$suffix}");

    if ($binary === false) {
        throw new RuntimeException('PHPacker did not produce the expected executable.');
    }

    fwrite(STDOUT, "{$binary}\n");
    $output = getenv('GITHUB_OUTPUT');

    if ($output !== false && file_put_contents($output, "binary={$binary}\n", FILE_APPEND) === false) {
        throw new RuntimeException('Cannot record executable path.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
