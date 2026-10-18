<?php

declare(strict_types=1);

namespace App\Commands\Js;

use App\Frontend\FrontendUpdateCommand;
use App\Frontend\JsManagedDistribution;
use App\Frontend\ManagedDistribution;

class Update extends FrontendUpdateCommand
{
    protected $signature = 'js:update
        {--path= : Application root}
        {--dry-run : Validate and preview without writing}
        {--yes : Confirm the update}
        {--force : Overwrite modified files inside scripts/arix only}';

    protected $description = 'Safely update framework JavaScript inside scripts/arix';

    protected function distribution(): ManagedDistribution
    {

        return $this->laravel->make(JsManagedDistribution::class);
    }
}
