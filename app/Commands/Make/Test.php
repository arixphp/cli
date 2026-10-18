<?php

declare(strict_types=1);

namespace App\Commands\Make;

use App\Make\GenerationPolicy;

use function base_path;
use function file_get_contents;

class Test extends MakeCommand
{
    protected $signature = 'make:test
        {name : Test name}
        {--path= : Application root, relative to the invocation directory or absolute}
        {--force : Replace an existing generated file}
        {--unit : Create a unit test}';

    protected $description = 'Create an Arix Pest test';

    protected function policy(): GenerationPolicy
    {
        $stub = file_get_contents(base_path('resources' . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR . ($this->option('unit') ? 'test.unit.stub' : 'test.feature.stub')));

        if ($stub === false) {
            throw new \RuntimeException('Test stub is missing.');
        }

        return new GenerationPolicy(
            $this->option('unit') ? 'tests/Unit' : 'tests/Feature',
            '',
            'Test',
            '.php',
            $stub,
            GenerationPolicy::CLASS_NAME
        );
    }
}
