<?php

declare(strict_types=1);

namespace App\Make;

use RuntimeException;

use function array_map;
use function array_pop;
use function explode;
use function preg_match;
use function str_ends_with;
use function str_replace;
use function strtolower;

class NameNormalizer
{
    /** @return array{list<string>, string} */
    public static function class(string $name, string $suffix): array
    {
        $segments = self::segments(str_replace('\\', '/', $name));
        $class = array_pop($segments);

        if ($class === null || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $class)) {
            throw new RuntimeException('Invalid class name.');
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment)) {
                throw new RuntimeException('Invalid class name.');
            }
        }

        if (! str_ends_with($class, $suffix)) {
            $class .= $suffix;
        }

        return [$segments, $class];
    }

    /** @return list<string> */
    public static function view(string $name): array
    {
        return array_map(strtolower(...), self::segments(str_replace(['\\', '.'], '/', $name)));
    }

    /** @return list<string> */
    private static function segments(string $name): array
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, '//')) {
            throw new RuntimeException('Invalid generated name.');
        }

        $segments = explode('/', $name);

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $segment)) {
                throw new RuntimeException('Invalid generated name.');
            }
        }

        return $segments;
    }
}
