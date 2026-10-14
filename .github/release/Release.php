<?php

declare(strict_types=1);

namespace Arix\Release;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

use function is_string;

class Release
{
    public static function version(string $tag): string
    {
        $number = '(?:0|[1-9][0-9]*)';
        $identifier = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
        $pattern = "~\\Av?{$number}\\.{$number}\\.{$number}(?:-{$identifier}(?:\\.{$identifier})*)?(?:\\+[0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*)?\\z~";

        if (preg_match($pattern, $tag) !== 1) {
            throw new RuntimeException('Expected a SemVer tag such as 1.2.3 or 1.2.3-rc.1.');
        }

        return str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
    }

    public static function title(string $tag): string
    {
        return 'v' . self::version($tag);
    }

    public static function prerelease(string $tag): bool
    {
        $version = explode('+', self::version($tag), 2)[0];

        return str_contains($version, '-');
    }

    public static function reportedVersion(string $output, string $expected): void
    {
        self::version($expected);

        if (preg_match('/\A[^\r\n]+ ([^\s]+)\z/', trim($output), $matches) !== 1 || $matches[1] !== $expected) {
            throw new RuntimeException('Packaged CLI version does not match the release tag.');
        }
    }

    /** @return list<string> */
    public static function archives(string $tag): array
    {
        $version = self::version($tag);

        return array_map(static fn (string $target): string => "arix-{$version}-{$target}", [
            'linux-x64.tar.gz', 'linux-arm64.tar.gz', 'macos-x64.tar.gz', 'macos-arm64.tar.gz', 'windows-x64.zip',
        ]);
    }

    public static function checksums(string $tag, string $directory): void
    {
        $lines = [];

        foreach (self::archives($tag) as $name) {
            $hash = self::hash($directory . DIRECTORY_SEPARATOR . $name);
            $lines[] = "{$hash}  {$name}";
        }

        $path = $directory . DIRECTORY_SEPARATOR . 'SHA256SUMS';

        if (is_link($path) || file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new RuntimeException('Cannot write SHA256SUMS.');
        }
    }

    public static function verify(string $tag, string $source, string $download): void
    {
        foreach ([...self::archives($tag), 'SHA256SUMS'] as $name) {
            if (! hash_equals(self::hash($source . DIRECTORY_SEPARATOR . $name), self::hash($download . DIRECTORY_SEPARATOR . $name))) {
                throw new RuntimeException("Downloaded asset does not match: {$name}");
            }
        }
    }

    private static function hash(string $path): string
    {
        if (is_link($path) || ! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException("Missing, linked or empty asset: {$path}");
        }

        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException("Cannot hash asset: {$path}");
        }

        return $hash;
    }

    public static function report(string $path): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = is_file($path) && $document->load($path, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $loaded || $document->doctype !== null) {
            throw new RuntimeException('Missing or invalid integration report.');
        }

        $xpath = new DOMXPath($document);
        $tests = $xpath->query('//testcase');
        $problems = $xpath->query('//skipped | //failure | //error');

        if ($tests === false || $tests->length === 0 || $problems === false || $problems->length !== 0) {
            throw new RuntimeException('Integration tests must run without failures, errors or skips.');
        }
    }

    public static function runtime(string $path, string $checksum): string
    {
        if (! hash_equals($checksum, self::hash($path))) {
            throw new RuntimeException('Embedded runtime checksum does not match the pinned download.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Invalid runtime ZIP.');
        }

        try {
            $binary = $zip->getFromName('micro.sfx');
        } finally {
            $zip->close();
        }

        if (! is_string($binary) || $binary === '') {
            throw new RuntimeException('Runtime ZIP has no micro.sfx binary.');
        }

        return $binary;
    }
}
