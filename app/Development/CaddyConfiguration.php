<?php

declare(strict_types=1);

namespace App\Development;

use App\Assets\ServerPolicyRenderer;
use Arix\Assets\AssetPolicy;
use RuntimeException;
use Symfony\Component\Process\Process;

use function count;
use function is_array;

class CaddyConfiguration
{
    private const array WATCH_PATTERNS = [
        'app/**/*.php',
        'index.php',
        'robots.txt',
    ];

    private const string MERCURE_SNIPPET = "mercure {\ntransport local\nanonymous\n}";

    public readonly string $token;

    public function __construct(private readonly string $base)
    {
        $this->token = bin2hex(random_bytes(16));
    }

    public function environment(DevOptions $options, int $port): array
    {
        return [
            'ARIX_DEV_ROOT' => $options->root,
            'ARIX_DEV_HOST' => $options->host,
            'ARIX_DEV_PORT' => (string) $port,
            'ARIX_DEV_ADDRESS' => $options->site($port),
            'ARIX_DEV_RELOAD' => $options->reload ? '1' : '0',
            'FRANKENPHP_HOT_RELOAD' => false,
        ];
    }

    public function prepare(DevOptions $options, int $port, string $directory, string $executable): string
    {
        $contents = $this->renderTemplate($this->loadTemplate($options), $options);

        $file = $this->materializeCaddyfile($contents, $directory);

        $raw = $this->adaptCaddyfile($file, $options, $port, $executable);

        $raw = $this->applyRootPlaceholder($raw, $options);

        [$config, $key] = $this->validateTopology($raw, $options, $port);

        $this->validateRootsAndReload($config, $options);

        return $this->finalizeConfiguration($raw, $key, $directory, $options, $port, $executable);
    }

    public function readinessPath(): string
    {
        return "/__arix_dev_ready_{$this->token}";
    }

    private function loadTemplate(DevOptions $options): string
    {
        $source = $options->caddyfile ?? $this->base . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'dev' . DIRECTORY_SEPARATOR . 'Caddyfile.template';

        $contents = @file_get_contents($source);

        if ($contents === false) {
            throw new RuntimeException('Cannot read development Caddyfile resource.');
        }

        if ($options->caddyfile !== null && (! str_contains($contents, '{$ARIX_DEV_ROOT}') || ! str_contains($contents, '{$ARIX_DEV_ADDRESS}'))) {
            throw new RuntimeException('Custom Caddyfile must consume {$ARIX_DEV_ROOT} and {$ARIX_DEV_ADDRESS}, with an explicit loopback bind when reload is enabled.');
        }

        return $contents;
    }

    private function renderTemplate(string $contents, DevOptions $options): string
    {
        $mercure = $options->reload ? self::MERCURE_SNIPPET : '';

        $hotReload = $options->reload ? $this->buildWatchBlock() : '';

        return str_replace(
            ['@@MERCURE@@', '@@HOT_RELOAD@@', '@@ASSET_POLICY@@'],
            [$mercure, $hotReload, (new ServerPolicyRenderer(new AssetPolicy()))->render('caddy')],
            $contents,
        );
    }

    private function buildWatchBlock(): string
    {
        $watch = "hot_reload {\n" . "topic arix-dev-{$this->token}\n";

        $patterns = self::WATCH_PATTERNS;

        foreach (new AssetPolicy()->directories() as $directory => $extensions) {
            $patterns[] = $directory . '/**/*.{' . implode(',', $extensions) . '}';
        }

        foreach ($patterns as $pattern) {
            $watch .= "watch {$pattern}\n";
        }

        $watch .= '}';

        return $watch;
    }

    private function materializeCaddyfile(string $contents, string $directory): string
    {
        $file = $directory . DIRECTORY_SEPARATOR . 'Caddyfile';

        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException('Cannot materialize development Caddyfile.');
        }

        return $file;
    }

    private function adaptCaddyfile(string $file, DevOptions $options, int $port, string $executable): string
    {
        $process = new Process([$executable, 'adapt', '--config', $file, '--adapter', 'caddyfile'], $options->root, $this->environment($options, $port), timeout: 20);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Invalid development Caddyfile: ' . $process->getErrorOutput());
        }

        return $process->getOutput();
    }

    private function applyRootPlaceholder(string $raw, DevOptions $options): string
    {
        if ($options->caddyfile !== null) {

            return $raw;
        }

        if (substr_count($raw, '"ARIX_DEV_ROOT_PLACEHOLDER"') !== 1) {
            throw new RuntimeException('Development Caddyfile template lost its root placeholder.');
        }

        return str_replace('"ARIX_DEV_ROOT_PLACEHOLDER"', json_encode($options->root), $raw);
    }

    private function validateTopology(string $raw, DevOptions $options, int $port): array
    {
        $config = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $servers = $config['apps']['http']['servers'] ?? [];

        $host = str_contains($options->host, ':') ? "[{$options->host}]" : $options->host;

        if (count($servers) !== 1 || ($config['admin']['disabled'] ?? false) !== true) {
            throw new RuntimeException('Development configuration requires one HTTP server and admin off.');
        }

        $key = array_key_first($servers);

        if (($servers[$key]['listen'] ?? []) !== ["{$host}:{$port}"]) {
            throw new RuntimeException('Caddy listener does not match requested host/port; use bind {$ARIX_DEV_HOST}.');
        }

        return [$config, $key];
    }

    private function validateRootsAndReload(array $config, DevOptions $options): void
    {
        $roots = [];

        $reloads = [];

        $this->inspect($config, $roots, $reloads);

        $this->validateRoots($roots, $options);

        if ($roots === [] || ($options->reload ? $reloads === [] : $reloads !== [])) {
            throw new RuntimeException('Custom Caddy root or hot_reload setting contradicts command options.');
        }
    }

    private function validateRoots(array $roots, DevOptions $options): void
    {

        foreach ($roots as $root) {
            if (realpath($root) !== $options->root) {
                throw new RuntimeException('Caddy root does not match application directory.');
            }
        }

    }

    private function finalizeConfiguration(string $raw, string|int $key, string $directory, DevOptions $options, int $port, string $executable): string
    {
        // Keep JSON objects as objects: Caddy modules distinguish {} from [].
        $document = json_decode($raw, flags: JSON_THROW_ON_ERROR);

        array_unshift($document->apps->http->servers->{$key}->routes, [
            'match' => [['path' => [$this->readinessPath()]]],
            'handle' => [['handler' => 'static_response', 'body' => $this->token, 'headers' => ['Cache-Control' => ['no-store']]]],
            'terminal' => true,
        ]);

        $json = $directory . DIRECTORY_SEPARATOR . 'caddy.json';

        file_put_contents($json, json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $validate = new Process([$executable, 'validate', '--config', $json], $options->root, $this->environment($options, $port), timeout: 20);

        $validate->run();

        if (! $validate->isSuccessful()) {
            throw new RuntimeException('Cannot validate development configuration: ' . $validate->getErrorOutput());
        }

        return $json;
    }

    private function inspect(array $node, array &$roots, array &$reloads): void
    {

        if (isset($node['root'])) {
            $roots[] = $node['root'];
        }

        if (isset($node['hot_reload'])) {
            $reloads[] = $node['hot_reload'];
        }

        foreach ($node as $value) {

            if (is_array($value)) {
                $this->inspect($value, $roots, $reloads);
            }
        }
    }
}
