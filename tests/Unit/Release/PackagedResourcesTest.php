<?php

declare(strict_types=1);

namespace Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function in_array;

class PackagedResourcesTest extends TestCase
{
    #[Test]
    public function it_packages_every_bundled_frontend_policy_and_server_resource(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode(file_get_contents($root . DIRECTORY_SEPARATOR . 'box.json'), true, flags: JSON_THROW_ON_ERROR);
        $packaged = $manifest['files'];
        $missing = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'resources', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = 'resources/' . substr($file->getPathname(), strlen($root . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR));

            if (! in_array(str_replace(DIRECTORY_SEPARATOR, '/', $relative), $packaged, true)) {
                $missing[] = $relative;
            }
        }

        foreach ([
            'core/Assets/AssetPolicy.php',
            'core/Assets/AssetType.php',
            'core/Assets/public-policy.json',
            'core/Autoload/AutoloadConfiguration.php',
            'core/Autoload/ClassLoader.php',
        ] as $required) {
            if (! in_array($required, $packaged, true)) {
                $missing[] = $required;
            }
        }

        $this->assertSame([], $missing);

        foreach ($packaged as $entry) {
            $this->assertFileExists($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry), $entry);
        }
    }
}
