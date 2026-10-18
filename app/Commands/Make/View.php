<?php

declare(strict_types=1);

namespace App\Commands\Make;

use App\Make\GenerationPolicy;

class View extends MakeCommand
{
    protected $signature = 'make:view
        {name : View name}
        {--path= : Application root, relative to the invocation directory or absolute}
        {--force : Replace an existing generated file}';

    protected $description = 'Create an empty Arix view';

    protected function policy(): GenerationPolicy
    {
        return new GenerationPolicy(
            'app/views',
            '',
            '',
            '.arix.php',
            '',
            GenerationPolicy::VIEW_NAME
        );
    }
}
