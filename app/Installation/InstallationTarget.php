<?php

declare(strict_types=1);

namespace App\Installation;

use InvalidArgumentException;

class InstallationTarget
{
    /** @param array<string, int>|null $identity */
    private function __construct(
        public readonly string $parent,
        public readonly string $path,
        public readonly array|null $identity,
    ) {}

    public static function resolve(string $name, string $directory, string $cwd): self
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,99}\z/', $name) !== 1 || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/i', $name) === 1) {
            throw new InvalidArgumentException('Invalid application name. Use 1-100 letters, numbers, dashes or underscores, starting with a letter or number; reserved device names are not allowed.');
        }

        if ($directory === '' || str_contains($directory, "\0")) {
            throw new InvalidArgumentException('The parent directory must be an existing filesystem directory.');
        }

        $absolute = str_starts_with($directory, DIRECTORY_SEPARATOR) || preg_match('/\A[A-Za-z]:[\\\\\/]/', $directory) === 1;
        $parent = realpath($absolute ? $directory : $cwd . DIRECTORY_SEPARATOR . $directory);

        if ($parent === false || ! is_dir($parent) || ! is_readable($parent) || ! is_writable($parent)) {
            throw new InvalidArgumentException('The parent directory must exist and be readable and writable.');
        }

        $path = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;

        return new self($parent, $path, self::inspect($path));
    }

    public function assertUnchanged(): void
    {
        if (! is_writable($this->parent) || self::inspect($this->path) !== $this->identity) {
            throw new InvalidArgumentException("Destination changed during installation: {$this->path}. Nothing was overwritten.");
        }
    }

    /** @return array<string, int>|null */
    private static function inspect(string $path): array|null
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            throw new InvalidArgumentException("Destination is a symbolic link: {$path}. Choose an absent or empty directory.");
        }

        if (! file_exists($path)) {

            return null;
        }

        if (! is_dir($path) || ! is_readable($path) || ! is_writable($path)) {
            throw new InvalidArgumentException("Destination must be a readable, writable empty directory: {$path}.");
        }

        $entries = @scandir($path);
        $stat = @lstat($path);

        if ($entries === false || $stat === false || $entries !== ['.', '..']) {
            throw new InvalidArgumentException("Destination is not empty or cannot be inspected: {$path}. Choose another name or directory.");
        }

        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'mode' => $stat['mode']];
    }
}
