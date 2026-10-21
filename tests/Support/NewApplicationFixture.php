<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use ZipArchive;

class NewApplicationFixture
{
    public readonly string $root;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-new-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    public function path(string $relative): string
    {

        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function write(string $relative, string $contents): string
    {
        $path = $this->path($relative);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    public function cleanup(): void
    {
        (new Filesystem())->remove($this->root);
    }

    /** @param array<string, string>|null $entries */
    public function archive(array|null $entries = null, string $name = 'fixture.zip'): string
    {
        $zip = new ZipArchive();
        $path = $this->path($name);
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries ?? self::skeleton() as $entry => $contents) {
            $zip->addFromString($entry, $contents);
        }

        $zip->close();

        return $path;
    }

    /** @return array<string, string> */
    public static function skeleton(): array
    {

        return [
            'app-sha/.arix.json' => '{"format":1,"core_contract":"arix-core-v1"}',
            'app-sha/.htaccess' => 'RewriteEngine On',
            'app-sha/.gitignore' => '/storage/cache',
            'app-sha/AGENTS.md' => 'Skeleton instructions.',
            'app-sha/index.php' => '<?php throw new RuntimeException("Do not execute downloaded PHP");',
            'app-sha/Caddyfile' => 'rewrite * /index.php',
            'app-sha/web.config' => '<configuration />',
            'app-sha/core/autoload.php' => '<?php // downloaded core',
            'app-sha/core/.htaccess' => 'Require all denied',
            'app-sha/app/.htaccess' => 'Require all denied',
            'app-sha/app/routes.php' => '<?php // routes',
            'app-sha/storage/.htaccess' => 'Require all denied',
            'app-sha/scripts/arix/index.js' => 'export const version = "test";',
            'app-sha/scripts/arix/dev-reload.js' => '// reload',
            'app-sha/scripts/.htaccess' => 'Require all denied',
            'app-sha/styles/arix/base.css' => '/* base */',
            'app-sha/styles/arix/components.css' => '/* components */',
            'app-sha/styles/arix/tokens.css' => '/* tokens */',
            'app-sha/styles/arix/utilities.css' => '/* utilities */',
            'app-sha/styles/.htaccess' => 'Require all denied',
            'app-sha/fonts/.htaccess' => 'Require all denied',
            'app-sha/media/.htaccess' => 'Require all denied',
            'app-sha/media/arix-icon.webp' => 'icon',
            'app-sha/media/arix-emblem.webp' => 'emblem',
            'app-sha/media/arix-combinationmark.webp' => 'combinationmark',
        ];
    }
}
