<?php

declare(strict_types=1);

namespace App\Make;

use RuntimeException;

use function dirname;
use function file_exists;
use function file_put_contents;
use function implode;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rename;
use function str_replace;
use function tempnam;
use function unlink;

class Generator
{
    public function generate(string $root, GenerationPolicy $policy, string $name, bool $force): GenerationResult
    {
        [$segments, $class, $filename] = $this->name($policy, $name);

        $directory = $this->directory($root, $policy->directory, $segments);
        $file = $directory . DIRECTORY_SEPARATOR . $filename;
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace($root, '', $file), DIRECTORY_SEPARATOR));

        $existing = $this->existing($file, $relative, $force);

        if ($existing !== null) {
            return $existing;
        }

        $this->create($directory);
        $contents = str_replace(['{{ namespace }}', '{{ class }}'], [$policy->namespace . ($segments === [] ? '' : '\\' . implode('\\', $segments)), $class ?? ''], $policy->stub);
        $this->write($directory, $file, $contents);

        return new GenerationResult($relative, true);
    }

    /** @return array{list<string>, null|string, string} */
    private function name(GenerationPolicy $policy, string $name): array
    {
        [$segments, $class] = $policy->mode === GenerationPolicy::CLASS_NAME
            ? NameNormalizer::class($name, $policy->suffix)
            : [NameNormalizer::view($name), null];
        $filename = $class === null ? $segments[array_key_last($segments)] . $policy->extension : $class . $policy->extension;

        if ($class === null) {
            array_pop($segments);
        }

        return [$segments, $class, $filename];
    }

    private function existing(string $file, string $relative, bool $force): GenerationResult|null
    {
        if (! file_exists($file) && ! is_link($file)) {
            return null;
        }

        if (is_link($file) || ! is_file($file)) {
            throw new RuntimeException('Generated file is not a regular file.');
        }

        return $force ? null : new GenerationResult($relative, false);
    }

    private function write(string $directory, string $file, string $contents): void
    {
        $temporary = tempnam($directory, '.arix-make-');

        if ($temporary !== false && file_put_contents($temporary, $contents) !== false && rename($temporary, $file)) {
            return;
        }

        if ($temporary !== false && is_file($temporary)) {
            unlink($temporary);
        }

        throw new RuntimeException('Cannot write generated file.');
    }

    /** @param list<string> $segments */
    private function directory(string $root, string $relative, array $segments): string
    {
        $path = $root;

        foreach (array_merge(explode('/', $relative), $segments) as $segment) {
            $path .= DIRECTORY_SEPARATOR . $segment;

            if (is_link($path)) {
                throw new RuntimeException("Generated path is linked: {$path}");
            }
        }

        return $path;
    }

    private function create(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create generated directory: {$directory}");
        }

        $path = $directory;

        while ($path !== dirname($path)) {
            if (is_link($path)) {
                throw new RuntimeException("Generated path is linked: {$path}");
            }

            $path = dirname($path);
        }
    }
}
