<?php

declare(strict_types=1);

use Arix\Release\Publisher;
use Arix\Release\Release;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'Release.php';
require __DIR__ . DIRECTORY_SEPARATOR . 'Publisher.php';

try {
    $command = $argv[1] ?? '';
    $tag = getenv('RELEASE_TAG') ?: '';
    $run = static function (array $arguments): string {
        $environment = [];
        $token = getenv('GH_TOKEN');

        if ($arguments[0] === 'git' && $token !== false && $token !== '') {
            $environment = [
                'GIT_CONFIG_COUNT' => '1',
                'GIT_CONFIG_KEY_0' => 'http.https://github.com/.extraheader',
                'GIT_CONFIG_VALUE_0' => 'AUTHORIZATION: basic ' . base64_encode("x-access-token:{$token}"),
            ];
        }

        $process = new Process($arguments, env: $environment);
        $process->setTimeout(600);
        $process->mustRun();

        return $process->getOutput();
    };

    switch ($command) {
        case 'resolve':
            $version = Release::version($tag);
            $sha = trim($run(['git', 'rev-parse', '--verify', "refs/tags/{$tag}^{commit}"]));
            $run(['git', 'merge-base', '--is-ancestor', $sha, 'origin/main']);
            $publisher = new Publisher(getenv('GITHUB_REPOSITORY') ?: '', $run);
            $publisher->remoteTag($tag, $sha);
            $output = getenv('GITHUB_OUTPUT');

            if ($output === false || file_put_contents($output, "tag={$tag}\nversion={$version}\nsha={$sha}\n", FILE_APPEND) === false) {
                throw new RuntimeException('Cannot record resolved release.');
            }
            break;
        case 'draft':
            (new Publisher(getenv('GITHUB_REPOSITORY') ?: '', $run))->draft($tag, getenv('RELEASE_SHA') ?: '');
            break;
        case 'publish':
            (new Publisher(getenv('GITHUB_REPOSITORY') ?: '', $run))->publish($tag, getenv('RELEASE_SHA') ?: '', $argv[2] ?? 'dist');
            break;
        case 'report':
            Release::report($argv[2] ?? '');
            break;
        default:
            throw new RuntimeException('Expected resolve, draft, publish or report.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
