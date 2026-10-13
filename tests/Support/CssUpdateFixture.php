<?php

declare(strict_types=1);

namespace Tests\Support;

class CssUpdateFixture extends TempTree
{
    public function stylesheet(string $file, string $version, string $suffix = ''): string
    {

        return "/* Arix CSS version: {$version} */\n/* {$file}{$suffix} */\n.layer-{$version} { color: red; }\n";
    }

    /** @return array<string, string> */
    public function versionSet(string $version, string $suffix = ''): array
    {
        $bodies = [];

        foreach (['base.css', 'components.css', 'tokens.css', 'utilities.css'] as $file) {
            $bodies[$file] = $this->stylesheet($file, $version, $suffix);
        }

        return $bodies;
    }

    /** @param array<string, string> $bodies
     * @return array<string, string> */
    public function hashes(array $bodies): array
    {
        $hashes = [];

        foreach ($bodies as $file => $body) {
            $hashes[$file] = hash('sha256', $body);
        }

        return $hashes;
    }

    /** @param array<string, string> $bodies
     * @param array<string, array<string, string>> $releases */
    public function distribution(string $relative, string $version, array $bodies, array $releases = []): string
    {
        foreach ($bodies as $file => $body) {
            $this->write("{$relative}/resources/css/{$file}", $body);
        }

        $recorded = array_replace($releases, [$version => $this->hashes($bodies)]);
        ksort($recorded);
        $this->write("{$relative}/resources/css/manifest.json", json_encode(['format' => 1, 'releases' => $recorded], JSON_THROW_ON_ERROR));

        return $this->path($relative);
    }

    public function application(string $relative = 'application', string|null $installedVersion = '1.0.0'): string
    {
        $this->write("{$relative}/index.php", '<?php // application entry point');
        $this->write("{$relative}/app/bootstrap.php", '<?php // application bootstrap');

        if ($installedVersion !== null) {
            foreach ($this->versionSet($installedVersion) as $file => $body) {
                $this->write("{$relative}/styles/{$file}", $body);
            }
        } elseif (! is_dir($this->path("{$relative}/styles"))) {
            mkdir($this->path("{$relative}/styles"), 0700, true);
        }

        $this->write("{$relative}/styles/app.css", '/* application entry */');

        return $this->path($relative);
    }
}
