<?php

declare(strict_types=1);

namespace App\Development;

use RuntimeException;

use function in_array;
use function is_string;

class DevOptions
{
    private function __construct(
        public readonly string $root,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $strictPort,
        public readonly string|null $caddyfile,
        public readonly string|null $frankenphp,
        public readonly bool $reload,
    ) {}

    public static function fromInput(array $input, string $cwd): self
    {
        $host = self::validatedHost($input);

        $port = self::validatedPort($input);

        $reload = ! ($input['no-reload'] ?? false);

        if ($reload && ! in_array(strtolower($host), ['127.0.0.1', '::1', 'localhost'], true)) {
            throw new RuntimeException('Hot reload exposes development details. Network binding requires --no-reload.');
        }

        self::validateStringOptions($input);

        $root = self::resolvedRoot($input, $cwd);

        return new self(
            $root,
            strtolower($host), $port, (bool) ($input['strict-port'] ?? false),
            isset($input['caddyfile']) ? DevelopmentTarget::file($input['caddyfile'], $cwd) : null,
            isset($input['frankenphp']) ? DevelopmentTarget::file($input['frankenphp'], $cwd) : null,
            $reload,
        );
    }

    public function address(int $port): string
    {
        $host = match ($this->host) {
            '0.0.0.0' => '127.0.0.1',
            '::' => '::1',
            default => $this->host,
        };

        $bracketed = str_contains($host, ':') ? "[{$host}]" : $host;

        return "http://{$bracketed}:{$port}";
    }

    public function site(int $port): string
    {
        if (in_array($this->host, ['0.0.0.0', '::'], true)) {

            return "http://:{$port}";
        }

        return $this->address($port);
    }

    private static function validatedHost(array $input): string
    {
        $host = $input['host'] ?? '127.0.0.1';

        if (self::hostIsInvalid($host)) {
            throw new RuntimeException('Host must be a valid hostname or IP address, without scheme or path.');
        }

        return $host;
    }

    private static function hostIsInvalid(mixed $host): bool
    {
        if (! is_string($host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return true;
        }

        if (preg_match('/[\s\/\\\\:#?]/', $host) === 1 && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return true;
        }

        return false;
    }

    private static function validatedPort(array $input): int
    {
        $port = (string) ($input['port'] ?? '8181');

        if (preg_match('/\A[0-9]{1,5}\z/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            throw new RuntimeException('Port must be an integer between 1 and 65535.');
        }

        return (int) $port;
    }

    private static function validateStringOptions(array $input): void
    {
        foreach (['path', 'caddyfile', 'frankenphp'] as $key) {
            if (isset($input[$key]) && ! is_string($input[$key])) {
                throw new RuntimeException("Invalid {$key} option.");
            }
        }
    }

    private static function resolvedRoot(array $input, string $cwd): string
    {
        $root = DevelopmentTarget::resolve($input['path'] ?? null, $cwd);

        if (str_contains($root, '{')) {
            throw new RuntimeException('Application root must not contain "{": Caddy reserves brace sequences for placeholders at request time.');
        }

        return $root;
    }
}
