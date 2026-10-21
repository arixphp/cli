<?php

declare(strict_types=1);

namespace App\Commands\Assets;

use App\Assets\ServerPolicyRenderer;
use Arix\Support\Path;
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;

use function in_array;
use function is_dir;
use function is_link;
use function is_string;

class RenderPolicy extends Command
{
    protected $signature = 'assets:policy
        {server : apache, caddy or iis}
        {--check= : Check a configuration file without writing}
        {--directory= : Check the .htaccess of a public resource directory}';

    protected $description = 'Render or verify the managed static asset exposure policy';

    public function handle(ServerPolicyRenderer $renderer): int
    {
        try {
            $server = $this->argument('server');
            $block = $renderer->render($server);
            $file = $this->option('check');
            $directory = $this->option('directory');

            if ($directory !== null) {
                return $this->checkDirectory($renderer, $directory);
            }

            if ($file !== null) {
                if (! is_string($file) || is_link($file) || ! is_file($file) || ! is_readable($file)) {
                    throw new InvalidArgumentException('Configuration file is unavailable.');
                }

                $configuration = file_get_contents($file);

                if (! $renderer->matches($server, $configuration)) {
                    $this->error('Asset policy is missing, duplicated or out of date.');

                    foreach ($renderer->violations($server, $configuration) as $violation) {
                        $this->line("  {$violation}");
                    }

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

    private function checkDirectory(ServerPolicyRenderer $renderer, string $directory): int
    {
        if (! is_string($directory) || $directory === '') {
            throw new InvalidArgumentException('Unknown public resource directory.');
        }

        $path = Path::isAbsolute($directory) ? $directory : getcwd() . DIRECTORY_SEPARATOR . $directory;
        $name = basename($path);

        if (! in_array($name, $renderer->directories(), true)) {
            throw new InvalidArgumentException('Unknown public resource directory.');
        }

        $file = $path . DIRECTORY_SEPARATOR . '.htaccess';

        if (! is_file($file) || is_link($file) || ! is_readable($file)) {
            $this->error("The {$name} directory policy is unavailable.");

            return self::FAILURE;
        }

        $actual = file_get_contents($file);

        if ($actual !== $renderer->renderDirectory($name)) {
            $this->error("The {$name} directory policy is out of date.");

            return self::FAILURE;
        }

        foreach ($this->linkedEntries($path) as $link) {
            $this->error("The {$name} directory contains a symbolic link: {$link}");

            return self::FAILURE;
        }

        $this->info("The {$name} directory policy matches.");

        return self::SUCCESS;
    }

    /**
     * The symbolic links of a public resource tree.
     *
     * A published directory must not link to a file outside the application.
     *
     * @return list<string>
     */
    private function linkedEntries(string $directory): array
    {
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        $links = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_link($path)) {
                $links[] = $path;

                continue;
            }

            if (is_dir($path)) {
                $links = [...$links, ...$this->linkedEntries($path)];
            }
        }

        return $links;
    }
}
