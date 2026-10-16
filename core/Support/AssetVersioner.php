<?php

declare(strict_types=1);

namespace Arix\Support;

use Arix\Routing\UrlGenerator;
use InvalidArgumentException;

use function in_array;
use function is_string;

class AssetVersioner
{
    /** @var array<string, list<string>> */
    private const array PUBLIC_EXTENSIONS = [
        'styles' => ['css'],
        'scripts' => ['js'],
        'assets' => ['avif', 'gif', 'ico', 'jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm', 'ogv', 'woff', 'woff2', 'ttf', 'otf'],
    ];

    /** @var list<string> */
    private const array FONT_EXTENSIONS = ['woff', 'woff2', 'ttf', 'otf'];

    private const string DIRECTORY_PATTERN = '/\A[A-Za-z0-9_-]+\z/D';

    private const string FILENAME_PATTERN = '/\A[A-Za-z0-9_-]+\.([A-Za-z0-9]+)\z/D';

    private readonly string $root;

    /** @var array<string, string> */
    private array $hashes = [];

    public function __construct(string $root, private readonly UrlGenerator $urls)
    {
        $real = realpath($root);

        if ($real === false || ! is_dir($real)) {
            throw new InvalidArgumentException('Asset root is missing.');
        }

        $this->root = $real;
    }

    public function asset(string $path): string
    {
        $relative = $this->relativePath($path);
        $file = $this->publicFile($relative);
        $hash = $this->hash($relative, $file);
        $url = $this->urls->to("/{$relative}");

        return "{$url}?v={$hash}";
    }

    private function relativePath(string $path): string
    {
        if (str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Invalid public asset path.');
        }

        $this->urls->asset($path);
        $relative = ltrim(rawurldecode($path), '/');

        if (! $this->isAllowedPublicPath($relative)) {
            throw new InvalidArgumentException('Asset is not an allowed public file.');
        }

        return $relative;
    }

    private function isAllowedPublicPath(string $relative): bool
    {
        $segments = explode('/', $relative);
        $file = array_pop($segments);

        if (! is_string($file) || ! $this->areAllowedDirectories($segments)) {

            return false;
        }

        $extension = $this->fileExtension($file);

        return $extension !== null && in_array($extension, $this->extensionsFor($segments), true);
    }

    /** @param list<string> $directories */
    private function areAllowedDirectories(array $directories): bool
    {
        if ($directories === [] || ! isset(self::PUBLIC_EXTENSIONS[$directories[0]])) {

            return false;
        }

        foreach ($directories as $directory) {
            if (preg_match(self::DIRECTORY_PATTERN, $directory) !== 1) {

                return false;
            }
        }

        return true;
    }

    private function fileExtension(string $file): string|null
    {
        if (preg_match(self::FILENAME_PATTERN, $file, $matches) !== 1) {

            return null;
        }

        return strtolower($matches[1]);
    }

    /** @param list<string> $directories
     * @return list<string>
     */
    private function extensionsFor(array $directories): array
    {
        if ($directories[0] === 'assets' && ($directories[1] ?? null) === 'fonts') {

            return self::FONT_EXTENSIONS;
        }

        return self::PUBLIC_EXTENSIONS[$directories[0]];
    }

    private function publicFile(string $relative): string
    {
        $file = $this->root;

        foreach (explode('/', $relative) as $segment) {
            if (! is_dir($file) || ! in_array($segment, scandir($file) ?: [], true)) {
                throw new InvalidArgumentException("Public asset is missing: {$relative}.");
            }

            $file .= DIRECTORY_SEPARATOR . $segment;

            if (is_link($file)) {
                throw new InvalidArgumentException('Linked public assets are not allowed.');
            }
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw new InvalidArgumentException("Public asset is not readable: {$relative}.");
        }

        return $file;
    }

    private function hash(string $relative, string $file): string
    {
        if (! isset($this->hashes[$relative])) {
            $hash = hash_file('sha256', $file);

            if ($hash === false) {
                throw new InvalidArgumentException('Cannot hash public asset.');
            }

            $this->hashes[$relative] = $hash;
        }

        return $this->hashes[$relative];
    }
}
