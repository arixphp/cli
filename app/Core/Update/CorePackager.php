<?php

declare(strict_types=1);

namespace App\Core\Update;

use Arix\Assets\AssetPolicy;
use InvalidArgumentException;
use RuntimeException;

use function in_array;
use function is_array;
use function is_string;

class CorePackager
{
    public function __construct(private readonly string $source, private readonly string $bundle) {}

    public function build(): CoreDistribution
    {
        $this->assertDevelopmentSource();

        CoreFiles::assertUnlinked($this->source);
        CoreFiles::assertUnlinked($this->bundle);

        $hashes = CoreFiles::inventory($this->source, true);
        $this->assertRuntimeResources($hashes);
        $imports = CoreDistribution::decode(CoreFiles::read(CoreFiles::local($this->source, 'imports.json')));

        $this->validateImports($imports, $hashes);

        $manifest = CoreDistribution::manifest($hashes);
        $contents = [];

        foreach ($hashes as $path => $hash) {
            $bytes = CoreFiles::read(CoreFiles::local($this->source, $path));

            if (hash('sha256', $bytes) !== $hash) {
                throw new RuntimeException("Source changed during packaging: {$path}");
            }

            $contents[$path] = base64_encode($bytes);
        }

        CoreDistribution::validateManifest($manifest);

        if (CoreFiles::inventory($this->source, true) !== $hashes) {
            throw new RuntimeException('Source changed during packaging.');
        }

        $resources = dirname($this->bundle);
        CoreFiles::assertUnlinked($resources);

        if (! is_dir($resources) && ! @mkdir($resources, 0755, true)) {
            throw new RuntimeException('Cannot create bundle resource directory.');
        }

        CoreFiles::write($this->bundle, json_encode(['manifest' => $manifest, 'contents' => $contents], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return CoreDistribution::load($this->bundle);
    }

    public function check(): CoreDistribution
    {
        $this->assertDevelopmentSource();
        $distribution = CoreDistribution::load($this->bundle);
        $hashes = CoreFiles::inventory($this->source, true);
        $this->assertRuntimeResources($hashes);

        if ($hashes !== $distribution->manifest['files']) {
            throw new RuntimeException('Core bundle is stale; run php arix core:package before updating.');
        }

        return $distribution;
    }

    private function assertDevelopmentSource(): void
    {
        if (str_starts_with($this->source, 'phar://') || str_starts_with($this->bundle, 'phar://')) {
            throw new RuntimeException('Core packaging requires the development source tree; compiled CLIs use their embedded bundle.');
        }
    }

    private function assertRuntimeResources(array $hashes): void
    {
        foreach (['.htaccess', 'stubs/error.html', 'Assets/public-policy.json'] as $resource) {
            if (! isset($hashes[$resource])) {
                throw new RuntimeException("Incomplete core release source: {$resource}");
            }
        }

        try {
            new AssetPolicy(CoreFiles::local($this->source, 'Assets/public-policy.json'));
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Invalid public policy in core release source.', previous: $exception);
        }
    }

    private function validateImports(array $imports, array $hashes): void
    {
        if (($imports['format'] ?? null) !== 1 || ! is_array($imports['files'] ?? null) || ! is_array($imports['packages'] ?? null)) {
            throw new RuntimeException('Invalid imported core manifest.');
        }

        $actual = [];

        foreach ($imports['packages'] as $metadata) {
            $destination = $metadata['destination'] ?? null;

            if (! is_string($destination) || ! in_array($destination, ['HttpFoundation', 'FastRoute', 'BladeOne', 'Cron'], true)) {
                throw new RuntimeException('Invalid imported core destination.');
            }

            foreach ($hashes as $path => $hash) {
                if (str_starts_with($path, "{$destination}/")) {
                    $actual[$path] = $hash;
                }
            }
        }

        foreach ($hashes as $path => $hash) {
            $component = explode('/', $path)[0];

            if (in_array($component, ['HttpFoundation', 'FastRoute', 'BladeOne', 'Cron'], true) && ! isset($actual[$path])) {
                throw new RuntimeException('Imported sources are unmanaged; refusing to package.');
            }
        }

        ksort($actual);
        $expected = $imports['files'];
        ksort($expected);

        if ($actual !== $expected) {
            throw new RuntimeException('Imported sources have local changes; refusing to package.');
        }
    }
}
