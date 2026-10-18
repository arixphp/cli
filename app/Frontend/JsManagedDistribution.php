<?php

declare(strict_types=1);

namespace App\Frontend;

use App\Core\Update\CoreFiles;
use App\Js\Update\JsDistribution;
use JsonException;
use RuntimeException;

use function is_array;

class JsManagedDistribution implements ManagedDistribution
{
    private function __construct(private readonly string $release, private readonly array $bodies, private readonly array $releases) {}

    public static function bundled(string $root): self
    {
        $base = $root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR;

        try {
            $manifest = json_decode(CoreFiles::read($base . 'managed-manifest.json'), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid managed JavaScript manifest.', previous: $exception);
        }

        if (! is_array($manifest) || array_keys($manifest) !== ['format', 'releases'] || $manifest['format'] !== 2) {
            throw new RuntimeException('Unsupported managed JavaScript manifest.');
        }

        $manifest['format'] = 1;
        $releases = ReleaseManifest::validate($manifest, ['index.js', 'dev-reload.js']);
        $bodies = ['index.js' => CoreFiles::read($base . 'arix.js'), 'dev-reload.js' => CoreFiles::read($base . 'dev-reload.js')];
        $version = JsDistribution::parseVersion($bodies['index.js']);
        $distribution = new self($version, $bodies, $releases);

        if (($releases[$version] ?? null) !== $distribution->inventory()) {
            throw new RuntimeException('Managed JavaScript does not match the recorded release.');
        }

        return $distribution;
    }

    public function directory(): string
    {

        return 'scripts/arix';
    }

    public function version(): string
    {

        return $this->release;
    }

    public function inventory(): array
    {

        return array_map(static fn (string $body): string => hash('sha256', $body), $this->bodies);
    }

    public function contents(string $file): string
    {

        return $this->bodies[$file] ?? throw new RuntimeException('Unknown managed JavaScript file.');
    }

    public function releaseOf(array $inventory): string|null
    {
        ksort($inventory);

        foreach ($this->releases as $version => $recorded) {
            ksort($recorded);

            if ($inventory === $recorded) {

                return $version;
            }
        }

        return null;
    }
}
