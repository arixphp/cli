<?php

declare(strict_types=1);

namespace App\Commands\Assets;

use App\Assets\ServerPolicyRenderer;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;

use function is_string;

class RenderPolicy extends Command
{
    protected $signature = 'assets:policy {server : apache, caddy or iis} {--check= : Check a configuration file without writing}';

    protected $description = 'Render or verify the managed static asset exposure policy';

    public function handle(ServerPolicyRenderer $renderer): int
    {

        try {
            $server = $this->argument('server');
            $block = $renderer->render($server);
            $file = $this->option('check');

            if ($file !== null) {
                if (! is_string($file) || is_link($file) || ! is_file($file) || ! is_readable($file)) {
                    throw new InvalidArgumentException('Configuration file is unavailable.');
                }

                if (! $renderer->matches($server, file_get_contents($file))) {
                    $this->error('Asset policy is missing, duplicated or out of date.');

                    return self::FAILURE;
                }

                $this->info('Asset policy matches. Native server verification is still required.');

                return self::SUCCESS;
            }

            $this->line($block);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        return self::SUCCESS;
    }
}
