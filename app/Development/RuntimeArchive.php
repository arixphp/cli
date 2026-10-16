<?php

declare(strict_types=1);

namespace App\Development;

use RuntimeException;
use ZipArchive;

use function in_array;

class RuntimeArchive
{
    public function extract(string $archive, string $destination, array $target): void
    {
        $this->assertEmptyDestination($destination);

        $zip = $this->openArchive($archive);

        try {
            $entries = $this->collectEntries($zip, $target);

            $this->writeEntries($zip, $destination, $entries, $target);
        } finally {
            $zip->close();
        }
    }

    private function assertEmptyDestination(string $destination): void
    {
        if (is_link($destination) || ! is_dir($destination) || scandir($destination) !== ['.', '..']) {
            throw new RuntimeException('Runtime extraction requires a private empty directory.');
        }

    }

    private function openArchive(string $archive): ZipArchive
    {
        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Invalid runtime ZIP.');
        }

        return $zip;
    }

    private function collectEntries(ZipArchive $zip, array $target): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > 10000) {
            throw new RuntimeException('Runtime ZIP entry limit exceeded.');
        }

        $entries = [];
        $seen = [];
        $total = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $total = $this->collectEntry($zip, $index, $target, $entries, $seen, $total);
        }

        return $entries;
    }

    private function collectEntry(ZipArchive $zip, int $index, array $target, array &$entries, array &$seen, int $total): int
    {
        $stat = $zip->statIndex($index);

        if ($stat === false || ($stat['encryption_method'] ?? 0) !== 0) {
            throw new RuntimeException('Invalid or encrypted runtime ZIP entry.');
        }

        $name = rtrim($stat['name'], '/');

        $this->assertSafePath($name);

        $directory = str_ends_with($stat['name'], '/');

        $this->assertRegularEntry($zip, $index, $name, $directory, $seen);

        $seen[strtolower($name)] = true;

        $total += $stat['size'];

        if ($stat['size'] < 0 || $total > ($target['max_unpacked_bytes'] ?? 536870912)) {
            throw new RuntimeException('Runtime ZIP unpacked size limit exceeded.');
        }

        $entries[] = [$index, $name, $directory, $stat['size']];

        return $total;
    }

    private function assertSafePath(string $name): void
    {

        foreach (explode('/', $name) as $segment) {
            if ($this->segmentIsUnsafe($segment)) {
                throw new RuntimeException('Unsafe runtime ZIP path.');
            }
        }
    }

    private function segmentIsUnsafe(string $segment): bool
    {
        if ($segment === '' || in_array($segment, ['.', '..'], true)) {
            return true;
        }

        if (preg_match('/\A[A-Za-z0-9_. -]+\z/', $segment) !== 1) {
            return true;
        }

        if (preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/i', $segment) === 1) {
            return true;
        }

        if (str_ends_with($segment, '.') || str_ends_with($segment, ' ')) {
            return true;
        }

        return false;
    }

    private function assertRegularEntry(ZipArchive $zip, int $index, string $name, bool $directory, array $seen): void
    {
        $os = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $os, $attributes)) {
            throw new RuntimeException('Cannot inspect runtime ZIP attributes.');
        }

        $type = ($attributes >> 16) & 0170000;

        if (($os === ZipArchive::OPSYS_UNIX && $type !== 0 && $type !== ($directory ? 0040000 : 0100000)) || isset($seen[strtolower($name)])) {
            throw new RuntimeException('Runtime ZIP contains links, special files or duplicate paths.');
        }
    }

    private function writeEntries(ZipArchive $zip, string $destination, array $entries, array $target): void
    {
        $written = 0;

        foreach ($entries as [$index, $name, $directory, $size]) {
            $written = $this->writeEntry($zip, $destination, $index, $name, $directory, $size, $target, $written);
        }
    }

    private function writeEntry(ZipArchive $zip, string $destination, int $index, string $name, bool $directory, int $size, array $target, int $written): int
    {
        $path = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $name);
        $parent = $directory ? $path : dirname($path);

        if (! is_dir($parent) && ! @mkdir($parent, 0700, true)) {
            throw new RuntimeException('Cannot create runtime ZIP directory.');
        }

        if ($directory) {
            return $written;
        }

        return $this->writeFile($zip, $index, $path, $size, $target, $written);
    }

    private function writeFile(ZipArchive $zip, int $index, string $path, int $size, array $target, int $written): int
    {
        $input = $zip->getStreamIndex($index);
        $output = @fopen($path, 'xb');

        if ($input === false || $output === false) {
            throw new RuntimeException('Cannot extract runtime ZIP file.');
        }

        try {
            $bytes = stream_copy_to_stream($input, $output, $size + 1);

            $written += $bytes;

            if ($bytes !== $size || $written > ($target['max_unpacked_bytes'] ?? 536870912)) {
                throw new RuntimeException('Runtime ZIP extraction size mismatch.');
            }

            return $written;
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
