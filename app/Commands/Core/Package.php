<?php

declare(strict_types=1);

namespace App\Commands\Core;

use App\Core\Update\CorePackager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

class Package extends Command
{
    protected $signature = 'core:package {--check : Verify the bundle against development sources without writing}';

    protected $description = 'Build or verify the standalone core bundle for CLI distribution';

    public function handle(CorePackager $packager): int
    {
        try {
            $distribution = $this->option('check') ? $packager->check() : $packager->build();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Core bundle verified: {$distribution->manifest['id']}");

        return self::SUCCESS;
    }
}
