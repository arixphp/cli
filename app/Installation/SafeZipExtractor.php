<?php

declare(strict_types=1);

namespace App\Installation;

use Arix\Autoload\AutoloadConfiguration;
use RuntimeException;
use ZipArchive;

use function array_slice;
use function count;
use function strlen;

class SafeZipExtractor
{
    public function __construct(private readonly int $maxBytes = 134217728, private readonly int $maxEntries = 10000) {}

    public function extract(string $archive, string $destination): void
    {
        if (is_link($destination) || ! is_dir($destination) || @scandir($destination) !== ['.', '..']) {
            throw new RuntimeException('Extraction destination must be a private empty directory.');
        }

        $zip = new ZipArchive();

        if ($zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Cannot open skeleton ZIP. The download is corrupt or not a ZIP archive.');
        }

        try {
            $entries = $this->inspect($zip);
            $bytes = 0;

            foreach ($entries as $entry) {
                $path = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
                $directory = $entry['directory'] ? $path : dirname($path);

                if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
                    throw new RuntimeException("Cannot create skeleton directory: {$directory}.");
                }

                if (! $entry['directory']) {
                    $this->extractFile($zip, $entry, $path, $bytes);
                }
            }

            $this->validateSkeleton($destination);
        } finally {
            $zip->close();
        }
    }

    /** @return list<array{index: int, path: string, directory: bool, size: int, crc: int}> */
    private function inspect(ZipArchive $zip): array
    {
        if ($zip->numFiles < 1 || $zip->numFiles > $this->maxEntries) {
            throw new RuntimeException('Skeleton ZIP exceeds the entry limit or is empty.');
        }

        $root = null;
        $total = 0;
        $paths = [];
        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false || ($stat['encryption_method'] ?? 0) !== 0) {
                throw new RuntimeException('Invalid or encrypted ZIP entry.');
            }

            $name = $stat['name'];
            $directory = str_ends_with($name, '/');
            $components = explode('/', $directory ? substr($name, 0, -1) : $name);

            foreach ($components as $component) {
                if ($component === '.' || $component === '..' || strlen($component) > 255
                    || preg_match('/\A[A-Za-z0-9_.-]+\z/', $component) !== 1
                    || str_ends_with($component, '.')
                    || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|\z)/i', $component) === 1) {
                    throw new RuntimeException('Unsafe or nonportable path in skeleton ZIP.');
                }
            }

            if (strlen($name) > 4096 || ($root !== null && $root !== $components[0])) {
                throw new RuntimeException('Skeleton ZIP must have exactly one wrapping root directory.');
            }

            $root = $components[0];
            $operatingSystem = 0;
            $attributes = 0;

            if (! $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
                throw new RuntimeException('Cannot inspect ZIP entry attributes.');
            }

            $type = ($attributes >> 16) & 0170000;

            if ($operatingSystem === ZipArchive::OPSYS_UNIX && $type !== 0 && $type !== ($directory ? 0040000 : 0100000)) {
                throw new RuntimeException('Skeleton ZIP contains a link or special file.');
            }

            $total += $stat['size'];

            if ($stat['size'] < 0 || $total > $this->maxBytes) {
                throw new RuntimeException('Skeleton ZIP exceeds the uncompressed size limit.');
            }

            if (count($components) === 1) {
                if (! $directory || isset($paths[''])) {
                    throw new RuntimeException('Invalid wrapping root or duplicate ZIP entry.');
                }

                $paths[''] = ['path' => '', 'directory' => true, 'explicit' => true];

                continue;
            }

            $relative = array_slice($components, 1);
            $prefix = '';

            foreach ($relative as $position => $component) {
                $prefix = $prefix === '' ? $component : "{$prefix}/{$component}";
                $key = strtolower($prefix);
                $explicit = $position === count($relative) - 1;
                $isDirectory = ! $explicit || $directory;
                $previous = $paths[$key] ?? null;

                if ($previous !== null && ($previous['path'] !== $prefix || $previous['directory'] !== $isDirectory || ($explicit && $previous['explicit']))) {
                    throw new RuntimeException('Duplicate entry or file/directory/case collision in skeleton ZIP.');
                }

                $paths[$key] = ['path' => $prefix, 'directory' => $isDirectory, 'explicit' => $explicit || ($previous['explicit'] ?? false)];
            }

            $entries[] = ['index' => $index, 'path' => implode('/', $relative), 'directory' => $directory, 'size' => $stat['size'], 'crc' => $stat['crc']];
        }

        return $entries;
    }

    /** @param array{index: int, path: string, directory: bool, size: int, crc: int} $entry */
    private function extractFile(ZipArchive $zip, array $entry, string $path, int &$total): void
    {
        $input = $zip->getStreamIndex($entry['index']);
        $output = @fopen($path, 'xb');

        if ($input === false || $output === false) {
            if ($input !== false) {
                fclose($input);
            }

            if ($output !== false) {
                fclose($output);
            }

            throw new RuntimeException("Cannot extract skeleton file: {$path}.");
        }

        $size = 0;
        $hash = hash_init('crc32b');

        try {
            while (! feof($input)) {
                $chunk = @fread($input, 65536);

                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    throw new RuntimeException('Cannot read compressed skeleton entry.');
                }

                $length = strlen($chunk);
                $total += $length;
                $size += $length;

                if ($total > $this->maxBytes || $size > $entry['size']) {
                    throw new RuntimeException('Skeleton entry exceeds the actual uncompressed size limit.');
                }

                hash_update($hash, $chunk);
                $offset = 0;

                while ($offset < $length) {
                    $written = @fwrite($output, substr($chunk, $offset));

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Cannot write extracted skeleton. Check disk space and permissions.');
                    }

                    $offset += $written;
                }
            }

            if ($size !== $entry['size'] || hash_final($hash) !== sprintf('%08x', $entry['crc'])) {
                throw new RuntimeException('Skeleton ZIP entry failed size or checksum validation.');
            }

            if (! @chmod($path, 0644)) {
                throw new RuntimeException("Cannot set safe permissions on {$path}.");
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function validateSkeleton(string $destination): void
    {
        foreach ($this->skeletonFiles() as $required) {
            $file = $destination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required);

            if (! is_file($file)) {
                throw new RuntimeException("Skeleton is missing required file: {$required}.");
            }
        }

        if (file_exists($destination . DIRECTORY_SEPARATOR . 'assets')) {
            throw new RuntimeException('Skeleton contains the legacy assets tree.');
        }

        if (! is_dir($destination . DIRECTORY_SEPARATOR . 'app')) {
            throw new RuntimeException('Skeleton is missing the app directory.');
        }

        AutoloadConfiguration::read($destination);
    }

    private function skeletonFiles(): array
    {
        return [
            'index.php',
            'core/autoload.php',
            'core/Autoload/AutoloadConfiguration.php',
            'core/Autoload/ClassLoader.php',
            '.arix.json',
            // The server policy is the only thing that keeps the private
            // directories and the application sources out of reach.
            '.htaccess',
            'Caddyfile',
            'web.config',
            'app/.htaccess',
            'core/.htaccess',
            'storage/.htaccess',
            'scripts/arix/index.js',
            'scripts/arix/dev-reload.js',
            'styles/arix/base.css',
            'styles/arix/components.css',
            'styles/arix/tokens.css',
            'styles/arix/utilities.css',
            'styles/.htaccess',
            'scripts/.htaccess',
            'fonts/.htaccess',
            'media/.htaccess',
            'media/arix-icon.webp',
            'media/arix-emblem.webp',
            'media/arix-combinationmark.webp',
        ];
    }
}
