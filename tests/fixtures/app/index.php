<?php

declare(strict_types=1);
use Arix\Http\Kernel;
use Arix\Runtime\HttpRuntime;

require __DIR__ . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';

exit((new HttpRuntime(__DIR__))->run(
    static fn (): Kernel => require __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php',
));
