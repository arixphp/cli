<?php

declare(strict_types=1);

namespace App\Installation;

use RuntimeException;

use function chmod;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function is_link;
use function rename;
use function str_starts_with;
use function tempnam;
use function unlink;

class ApplicationInstructions
{
    public const string FILENAME = 'AGENTS.md';

    public function __construct(private readonly string $source) {}

    public function write(string $application): void
    {
        $target = $application . DIRECTORY_SEPARATOR . self::FILENAME;

        if (is_link($target)) {
            throw new RuntimeException("Cannot replace a linked application instructions file: {$target}.");
        }

        if (is_link($this->source) || ! is_file($this->source)) {
            throw new RuntimeException("Cannot read the application instructions: {$this->source}.");
        }

        $contents = file_get_contents($this->source);

        if ($contents === false) {
            throw new RuntimeException("Cannot read the application instructions: {$this->source}.");
        }

        $temporary = tempnam($application, '.arix-instructions-');

        if ($temporary === false || ! str_starts_with($temporary, $application . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Cannot create a temporary application instructions file in {$application}.");
        }

        if (@file_put_contents($temporary, $contents) === false) {
            @unlink($temporary);

            throw new RuntimeException("Cannot write the application instructions: {$target}.");
        }

        @chmod($temporary, 0644);

        if (! @rename($temporary, $target)) {
            @unlink($temporary);

            throw new RuntimeException("Cannot write the application instructions: {$target}.");
        }
    }
}
