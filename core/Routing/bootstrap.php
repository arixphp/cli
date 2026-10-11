<?php

namespace Arix\Routing;

require __DIR__ . '/functions.php';

spl_autoload_register(function ($class) {
    if (strpos($class, 'Arix\Routing\\') === 0) {
        $name = substr($class, strlen('Arix\Routing'));
        require __DIR__ . strtr($name, '\\', DIRECTORY_SEPARATOR) . '.php';
    }
});
