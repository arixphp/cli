<?php

declare(strict_types=1);

namespace App\Commands\Make;

use App\Make\GenerationPolicy;

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
        return new GenerationPolicy(
            $this->option('unit') ? 'tests/Unit' : 'tests/Feature',
            '',
            'Test',
            '.php',
            "<?php\n\ndeclare(strict_types=1);\n\nit('has an example assertion', function (): void {\n    expect(true)->toBeTrue();\n});\n",
            GenerationPolicy::CLASS_NAME
        );
    }
}
