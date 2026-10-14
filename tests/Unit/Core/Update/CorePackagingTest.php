<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
use Phar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\CoreUpdateFixture;

class CorePackagingTest extends TestCase
{
    #[Test]
    public function it_loads_exact_core_bytes_from_a_box_compacted_phar_without_a_source_tree(): void
    {
        $fixture = new CoreUpdateFixture();
        $project = dirname(__DIR__, 4);

        try {
            $phar = $fixture->path('release/release.phar');
            $prebuilt = getenv('ARIX_TEST_CORE_PHAR');

            if ($prebuilt !== false && $prebuilt !== '') {
                $this->assertFileExists($prebuilt);
                $fixture->write('release/.placeholder', '');
                $this->assertTrue(copy($prebuilt, $phar));
            } else {
                $this->assertNotSame('Windows', PHP_OS_FAMILY, 'Box cannot build this fixture on Windows. Set ARIX_TEST_CORE_PHAR to the artifact built on Linux.');
                $source = $fixture->core('source', [
                    'Http/Kernel.php' => "<?php\n// Keep this comment and whitespace.\n\n",
                    '.htaccess' => "Require all denied\r\n",
                    'stubs/error.html' => "<!doctype html>\r\n<!-- Keep whitespace. -->\n<pre>{{message}}</pre>\n",
                ]);
                $fixture->write('release/resources/.placeholder', '');
                CoreDistribution::build($source, $fixture->path('release/resources/core.bundle'));

                foreach (['CoreFiles', 'CoreDistribution'] as $class) {
                    $fixture->write("release/app/{$class}.php", file_get_contents($project . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Update' . DIRECTORY_SEPARATOR . "{$class}.php"));
                }

                $fixture->write('release/main.php', <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__.'/app/CoreFiles.php';
require __DIR__.'/app/CoreDistribution.php';
$distribution = App\Core\Update\CoreDistribution::bundled(__DIR__);
echo json_encode([
    $distribution->contents('Http/Kernel.php'),
    $distribution->contents('.htaccess'),
    $distribution->contents('stubs/error.html'),
], JSON_THROW_ON_ERROR);
PHP);
                $configuration = json_decode(file_get_contents($project . DIRECTORY_SEPARATOR . 'box.json'), true, flags: JSON_THROW_ON_ERROR);
                $configuration['main'] = 'main.php';
                $configuration['directories'] = ['app'];
                $configuration['files'] = ['resources/core.bundle'];
                $configuration['output'] = 'release.phar';
                $configuration['check-requirements'] = false;
                $fixture->write('release/box.json', json_encode($configuration, JSON_THROW_ON_ERROR));
                $box = $project . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'laravel-zero' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'box';
                $process = new Process([PHP_BINARY, $box, 'compile', '--no-parallel', '--working-dir=' . $fixture->path('release')], $fixture->path('release'));
                $process->setTimeout(120);
                $process->run();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
            }

            $this->assertDirectoryDoesNotExist($fixture->path('release/core'));
            $this->assertDirectoryDoesNotExist($fixture->path('release/vendor'));
            $archive = new Phar($phar);
            $this->assertFalse(isset($archive['core']));
            $this->assertFalse(isset($archive['vendor']));
            unset($archive);

            $runtime = new Process([PHP_BINARY, $phar], $fixture->root);
            $runtime->run();
            $this->assertSame(0, $runtime->getExitCode(), $runtime->getErrorOutput());
            $this->assertSame([
                "<?php\n// Keep this comment and whitespace.\n\n",
                "Require all denied\r\n",
                "<!doctype html>\r\n<!-- Keep whitespace. -->\n<pre>{{message}}</pre>\n",
            ], json_decode($runtime->getOutput(), true, flags: JSON_THROW_ON_ERROR));

            $export = getenv('ARIX_EXPORT_CORE_PHAR');

            if ($export !== false && $export !== '') {
                $this->assertTrue(copy($phar, $export));
                $this->assertFileExists($export);
                $this->assertSame(hash_file('sha256', $phar), hash_file('sha256', $export));
            }
        } finally {
            $fixture->cleanup();
        }
    }
}
