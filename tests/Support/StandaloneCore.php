<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

class StandaloneCore
{
    private static TempTree|null $application = null;

    public static function autoload(string|null $root = null): string
    {
        if ($root === null) {

            if (self::$application === null) {
                self::$application = new TempTree();
                register_shutdown_function(static fn () => self::$application->cleanup());
            }

            $root = self::$application->root;
        }

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
