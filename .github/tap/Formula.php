<?php

declare(strict_types=1);

namespace Arix\Tap;

use Arix\Release\Release;
use RuntimeException;

use function array_key_exists;
use function is_string;

class Formula
{
    private const REPOSITORY = 'arixphp/cli';

    /** @var list<string> */
    private const TARGETS = ['linux-x64', 'linux-arm64', 'macos-x64', 'macos-arm64'];

    /** @param array<string, string> $checksums */
    public static function update(string $directory, string $version, array $checksums): void
    {
        $version = Release::version($version);
        $names = [];

        foreach (self::TARGETS as $target) {
            $names[] = "arix-{$version}-{$target}.tar.gz";
        }

        $archived = [];

        foreach (Release::archives($version) as $name) {
            if (! str_ends_with($name, '.zip')) {
                $archived[] = $name;
            }
        }

        if ($names !== $archived) {
            throw new RuntimeException('Tap targets diverged from release archives.');
        }

        foreach ($names as $name) {
            if (! array_key_exists($name, $checksums) || ! is_string($checksums[$name]) || preg_match('/\A[0-9a-f]{64}\z/', $checksums[$name]) !== 1) {
                throw new RuntimeException("Missing or invalid checksum: {$name}");
            }
        }

        $formula = $directory . DIRECTORY_SEPARATOR . 'Formula';

        if (! is_dir($formula) && ! mkdir($formula, 0755, true)) {
            throw new RuntimeException('Cannot prepare tap formula directory.');
        }

        $path = $formula . DIRECTORY_SEPARATOR . 'arix.rb';

        if (is_link($path) || file_put_contents($path, self::render($version, $checksums)) === false) {
            throw new RuntimeException('Cannot write tap formula.');
        }
    }

    /** @param array<string, string> $checksums */
    private static function render(string $version, array $checksums): string
    {
        $base = 'https://github.com/' . self::REPOSITORY . "/releases/download/{$version}";
        $entry = static fn (string $target): string => "      url \"{$base}/arix-{$version}-{$target}.tar.gz\"\n      sha256 \"{$checksums["arix-{$version}-{$target}.tar.gz"]}\"";
        $lines = [
            'class Arix < Formula',
            '  desc "Development and distribution tooling for ArixPHP"',
            '  homepage "https://github.com/' . self::REPOSITORY . '"',
            "  version \"{$version}\"",
            '  license "MIT"',
            '',
            '  on_macos do',
            '    if Hardware::CPU.arm?',
            $entry('macos-arm64'),
            '    else',
            $entry('macos-x64'),
            '    end',
            '  end',
            '',
            '  on_linux do',
            '    if Hardware::CPU.arm?',
            $entry('linux-arm64'),
            '    else',
            $entry('linux-x64'),
            '    end',
            '  end',
            '',
            '  def install',
            '    bin.install "arix"',
            '  end',
            '',
            '  test do',
            '    assert_match version.to_s, shell_output("#{bin}/arix --version")',
            '  end',
            'end',
        ];

        return implode("\n", $lines) . "\n";
    }
}
