<?php

declare(strict_types=1);

namespace App\Commands\Css;

use App\Css\Update\CssDistribution;
use App\Frontend\CssManagedDistribution;
use App\Frontend\FrontendUpdateCommand;
use App\Frontend\ManagedDistribution;

class Update extends FrontendUpdateCommand
{
    protected $signature = 'css:update
        {--path= : Application root}
        {--dry-run : Validate and preview without writing}
        {--yes : Confirm the update}
        {--force : Overwrite modified files inside styles/arix only}';

    protected $description = 'Safely update framework styles inside styles/arix';

    protected function distribution(): ManagedDistribution
    {

        return new CssManagedDistribution($this->laravel->make(CssDistribution::class));
    }
}
