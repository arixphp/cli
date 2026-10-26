<?php

declare(strict_types=1);

namespace Tests\Core\Autoload;

use Arix\Autoload\AutoloadConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class AutoloadConfigurationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'arix-manifest-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    #[Test]
    public function it_reads_production_and_development_mappings_without_merging_them(): void
    {
        $this->write(['version' => '1.0.0', 'autoload' => ['psr-4' => ['App\\' => 'app/', 'Domain\\' => 'domain/'], 'files' => ['helpers.php']], 'autoload-dev' => ['psr-4' => ['Tests\\' => 'tests/']]]);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'helpers.php', '<?php throw new \\LogicException("Metadata inspection executed PHP");');
        $configuration = AutoloadConfiguration::read($this->root);
        $this->assertSame(['App\\' => 'app/', 'Domain\\' => 'domain/'], $configuration->prefixes());
        $this->assertSame(['helpers.php'], $configuration->files());
        $this->assertSame(['Tests\\' => 'tests/'], $configuration->devPrefixes());
        $this->assertSame(realpath($this->root), $configuration->root());
    }

    #[Test]
    public function it_rejects_missing_malformed_and_unsupported_manifests(): void
    {
        $invalid = [null, '{', '[]', '{"format":1,"core_contract":"arix-core-v1"}', '{"version":"2.0.0","autoload":{"psr-4":{}}}', '{"version":"1.0.0","autoload":[]}', '{"version":"1.0.0","autoload":{"psr-4":null}}', '{"version":"1.0.0","autoload":{"files":null}}', '{"version":"1.0.0","autoload":{},"autoload-dev":null}'];

        foreach ($invalid as $contents) {
            $file = $this->root . DIRECTORY_SEPARATOR . '.arix.json';

            if (is_file($file)) {
                unlink($file);
            }

            if ($contents !== null) {
                file_put_contents($file, $contents);
            }

            $failure = null;

            try {
                AutoloadConfiguration::read($this->root);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }

            $this->assertInstanceOf(RuntimeException::class, $failure, 'Invalid application manifest accepted.');
        }
    }

    #[Test]
    public function it_rejects_unsafe_paths_invalid_prefixes_and_directory_lists(): void
    {
        $invalid = [
            ['App' => 'app/'], ['' => 'app/'], ['App\\' => '../outside'],
            ['App\\' => '/absolute'], ['App\\' => 'php://filter'],
            ['App\\' => 'app//classes'], ['App\\' => 'app/./classes'],
            ['App\\' => []], ['App\\' => [1]], ['App\\' => ['app/']],
        ];

        foreach ($invalid as $prefixes) {
            $this->write(['version' => '1.0.0', 'autoload' => ['psr-4' => $prefixes]]);

            $failure = null;

            try {
                AutoloadConfiguration::read($this->root);
            } catch (RuntimeException $exception) {
                $failure = $exception;
            }

            $this->assertInstanceOf(RuntimeException::class, $failure, 'Unsafe mapping accepted.');
        }
    }

    #[Test]
    public function it_rejects_linked_and_oversized_manifests(): void
    {
        $target = $this->root . DIRECTORY_SEPARATOR . 'target.json';
        file_put_contents($target, '{"version":"1.0.0","autoload":{"psr-4":{}}}');
        symlink($target, $this->root . DIRECTORY_SEPARATOR . '.arix.json');

        $failure = null;

        try {
            AutoloadConfiguration::read($this->root);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure, 'Linked manifest accepted.');

        unlink($this->root . DIRECTORY_SEPARATOR . '.arix.json');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.arix.json', str_repeat(' ', 65537));
        $this->expectException(RuntimeException::class);
        AutoloadConfiguration::read($this->root);
    }

    private function write(array $manifest): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.arix.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
