<?php

declare(strict_types=1);

namespace App\Commands\Make;

use App\Make\GenerationPolicy;
use RuntimeException;

use function base_path;
use function file_get_contents;

class Controller extends MakeCommand
{
    protected $signature = 'make:controller
        {name : Controller name}
        {--path= : Application root, relative to the invocation directory or absolute}
        {--force : Replace an existing generated file}';

    protected $description = 'Create an Arix controller';

    protected function policy(): GenerationPolicy
    {
        $stub = file_get_contents(base_path('resources' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . 'controller.stub'));

        if ($stub === false) {
            throw new RuntimeException('Controller stub is missing.');
        }

        return new GenerationPolicy('app/Controllers', 'App\\Controllers', 'Controller', '.php', $stub, GenerationPolicy::CLASS_NAME);
    }
}
