<?php

declare(strict_types=1);

namespace App\Commands\Make;

use App\Make\GenerationPolicy;

use RuntimeException;

use function base_path;
use function file_get_contents;

class Middleware extends MakeCommand
{
    protected $signature = 'make:middleware
        {name : Middleware name}
        {--path= : Application root, relative to the invocation directory or absolute}
        {--force : Replace an existing generated file}';

    protected $description = 'Create an Arix middleware';

    protected function policy(): GenerationPolicy
    {
        $stub = file_get_contents(base_path('resources' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'middleware.stub'));

        if ($stub === false) {
            throw new RuntimeException('Middleware stub is missing.');
        }

        return new GenerationPolicy('app/middleware', 'App\\Middleware', 'Middleware', '.php', $stub, GenerationPolicy::CLASS_NAME);
    }
}
