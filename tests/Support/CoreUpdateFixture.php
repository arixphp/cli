<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

class CoreUpdateFixture
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-update-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    public function path(string $relative): string
    {

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function write(string $relative, string $contents): string
    {
        $path = $this->path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    /** @param array<string, string> $files */
    public function core(string $relative, array $files = [], bool $includeResources = true): string
    {
        $files = array_replace([
            'autoload.php' => "<?php\ndeclare(strict_types=1);\n",
            'functions.php' => "<?php\ndeclare(strict_types=1);\n// helpers\n",
            'Http/Kernel.php' => "<?php\ndeclare(strict_types=1);\n// kernel\n",
            'imports.json' => '{"format":1,"packages":[],"files":[]}',
        ], $files);

        if ($includeResources) {
            $files = array_replace([
                '.htaccess' => "Require all denied\n",
                'stubs/error.html' => '<html lang="{{locale}}"><title>{{status}} — {{name}}</title><pre>{{message}}</pre></html>',
                'Assets/public-policy.json' => file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Assets' . DIRECTORY_SEPARATOR . 'public-policy.json'),
            ], $files);
        }

        foreach ($files as $name => $contents) {
            $this->write("{$relative}/{$name}", $contents);
        }

        return $this->path($relative);
    }

    public function application(string $relative = 'application'): string
    {
        $this->write("{$relative}/.arix.json", '{"format":1,"core_contract":"arix-core-v1"}');
        $this->write("{$relative}/index.php", '<?php // application entry point');
        $this->write("{$relative}/app/bootstrap.php", '<?php // application bootstrap');

        return $this->path($relative);
    }

    /** @param array<string, string> $files */
    public function installed(string $relative = 'application', array $files = [], bool $includeResources = true): string
    {
        $application = $this->application($relative);
        $core = $this->core("{$relative}/core", $files, $includeResources);
        $hashes = [];
        $resources = $includeResources ? ['.htaccess', 'stubs/error.html', 'Assets/public-policy.json'] : [];

        foreach (['autoload.php', 'functions.php', 'Http/Kernel.php', 'imports.json', ...$resources, ...array_keys($files)] as $name) {
            $hashes[$name] = hash_file('sha256', $core . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name));
        }

        ksort($hashes);
        $manifest = [
            'format' => 1,
            'contract' => 'arix-core-v1',
            'php' => '>=8.4.1',
            'extensions' => ['mbstring'],
            'files' => $hashes,
        ];
        $manifest['id'] = hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->write("{$relative}/core/distribution.json", json_encode($manifest, JSON_THROW_ON_ERROR));

        return $application;
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->root);
    }
}
