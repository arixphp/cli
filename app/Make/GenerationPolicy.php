<?php

declare(strict_types=1);

namespace App\Make;

use InvalidArgumentException;

use function in_array;
use function preg_match;

class GenerationPolicy
{
    public const CLASS_NAME = 'class';

    public const VIEW_NAME = 'view';

    public function __construct(
        public readonly string $directory,
        public readonly string $namespace,
        public readonly string $suffix,
        public readonly string $extension,
        public readonly string $stub,
        public readonly string $mode,
    ) {
        if (! preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*$/D', $directory) || ! in_array($mode, [self::CLASS_NAME, self::VIEW_NAME], true)) {
            throw new InvalidArgumentException('Invalid generation policy.');
        }
    }
}
