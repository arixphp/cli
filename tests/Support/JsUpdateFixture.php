<?php

declare(strict_types=1);

namespace Tests\Support;

class JsUpdateFixture extends TempTree
{
    public function framework(string $version, string $suffix = ''): string
    {

        return "// Arix UI {$version}{$suffix}\nexport const version = '{$version}';\nexport const marker = '{$version}{$suffix}';\n";
    }

    /** @param array<string, string> $releases */
    public function distribution(string $relative, string $version, string $body, array $releases = []): string
    {
        $this->write("{$relative}/resources/js/arix.js", $body);
        $hashes = array_replace($releases, [$version => hash('sha256', $body)]);
        ksort($hashes);
        foreach ($hashes as $release => $hash) {
            $hashes[$release] = ['arix.js' => $hash];
        }

        $this->write("{$relative}/resources/js/manifest.json", json_encode(['format' => 1, 'releases' => $hashes], JSON_THROW_ON_ERROR));

        return $this->path($relative);
    }

    public function application(string $relative = 'application', string|null $installedVersion = '1.0.0'): string
    {
        $this->write("{$relative}/index.php", '<?php // application entry point');
        $this->write("{$relative}/app/bootstrap.php", '<?php // application bootstrap');

        if ($installedVersion !== null) {
            $this->write("{$relative}/scripts/arix.js", $this->framework($installedVersion));
        } elseif (! is_dir($this->path("{$relative}/scripts"))) {
            mkdir($this->path("{$relative}/scripts"), 0700, true);
        }

        $this->write("{$relative}/scripts/app.js", '// application entry');

        return $this->path($relative);
    }
}
