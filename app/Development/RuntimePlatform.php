<?php

declare(strict_types=1);

namespace App\Development;

use RuntimeException;

class RuntimePlatform
{
    public static function detect(): string
    {
        return self::normalize(PHP_OS_FAMILY, php_uname('m'));
    }

    public static function normalize(string $os, string $architecture): string
    {
        $arch = match (strtolower($architecture)) {
            'x86_64', 'amd64' => 'x86_64',
            'arm64', 'aarch64' => 'arm64',
            default => throw new RuntimeException("Unsupported FrankenPHP architecture: {$architecture}."),
        };

        return match ($os) {
            'Linux' => $arch === 'arm64' ? 'linux-aarch64' : 'linux-x86_64',
            'Darwin' => "mac-{$arch}",
            'Windows' => $arch === 'x86_64' ? 'windows-x86_64' : throw new RuntimeException('Native Windows ARM64 FrankenPHP is not available; use Linux under WSL.'),
            default => throw new RuntimeException("Unsupported FrankenPHP platform: {$os}."),
        };
    }
}
