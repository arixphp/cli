<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use Throwable;

class TestApplication extends TempTree
{
    private static self|null $shared = null;

    public function __construct()
    {
        parent::__construct();
        $project = dirname(__DIR__, 2);
        $filesystem = new Filesystem();

        try {
            $filesystem->mirror($project . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app', $this->root);
            self::prepareCore($this->root);
            $filesystem->mkdir($this->path('storage/cache'), 0700);
        } catch (Throwable $exception) {
            $this->cleanup();

            throw $exception;
        }
    }

    public static function shared(): self
    {
        if (self::$shared === null) {
            self::$shared = new self();
            register_shutdown_function(static fn () => self::$shared->cleanup());
        }

        return self::$shared;
    }

    public static function prepareCore(string $root): string
    {
        $core = $root . DIRECTORY_SEPARATOR . 'core';

        if (! is_file($core . DIRECTORY_SEPARATOR . 'autoload.php')) {
            (new Filesystem())->mirror(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core', $core);
        }

        $marker = $root . DIRECTORY_SEPARATOR . '.arix.json';
        $manifest = is_file($marker)
            ? json_decode(file_get_contents($marker), true, flags: JSON_THROW_ON_ERROR)
            : ['version' => '1.0.0', 'autoload' => ['psr-4' => ['App\\' => 'app/']]];
        $manifest['autoload']['psr-4']['Arix\\'] = 'core/';
        $manifest['autoload']['files'] = array_values(array_unique([
            'core/functions.php', 'core/FastRoute/functions.php', ...($manifest['autoload']['files'] ?? []),
        ]));
        file_put_contents($marker, json_encode($manifest, JSON_THROW_ON_ERROR));

        return $core . DIRECTORY_SEPARATOR . 'autoload.php';
    }
}
