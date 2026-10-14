<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

use function strlen;

class RoutingTestCase extends TestCase
{
    protected string $directory;

    protected string $fixtureNamespace;

    protected function setUp(): void
    {
        parent::setUp();
        $id = 'Fixture' . bin2hex(random_bytes(8));
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . $id;
        $this->fixtureNamespace = "App\\Controllers\\{$id}";
        mkdir($this->directory . DIRECTORY_SEPARATOR . $id, 0755, true);
    }

    protected function controller(string $body, string $name = 'SampleController'): string
    {
        $namespace = $this->fixtureNamespace;
        $id = substr($namespace, strlen('App\\Controllers\\'));
        $source = "<?php\ndeclare(strict_types=1);\nnamespace {$namespace};\nuse Arix\\HttpFoundation\\Request;\nuse Arix\\HttpFoundation\\Response;\nclass {$name} { {$body} }\n";
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . "{$name}.php", $source);

        return "{$namespace}\\{$name}";
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
