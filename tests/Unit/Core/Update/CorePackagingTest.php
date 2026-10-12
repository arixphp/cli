<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Update;

use App\Core\Update\CoreDistribution;
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
            $source = $fixture->core('source', ['Http/Kernel.php' => "<?php\n// Keep this comment and whitespace.\n\n"]);
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
echo $distribution->contents('Http/Kernel.php');
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
            $this->assertDirectoryDoesNotExist($fixture->path('release/core'));

            $runtime = new Process([PHP_BINARY, $fixture->path('release/release.phar')], $fixture->root);
            $runtime->run();
            $this->assertSame(0, $runtime->getExitCode(), $runtime->getErrorOutput());
            $this->assertSame("<?php\n// Keep this comment and whitespace.\n\n", $runtime->getOutput());
        } finally {
            $fixture->cleanup();
        }
    }
}
