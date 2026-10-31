<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestApplication;

class TestApplicationTest extends TestCase
{
    #[Test]
    public function it_prepares_a_real_application_without_linking_or_mutating_the_source(): void
    {
        $app = new TestApplication();

        try {
            $source = dirname(__DIR__, 3);

            foreach (['autoload.php', 'Autoload/ClassLoader.php', 'HttpFoundation/Request.php'] as $file) {
                $original = $source . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
                $copy = $app->path("core/{$file}");
                $this->assertSame(hash_file('sha256', $original), hash_file('sha256', $copy));
                $this->assertFalse(is_link($copy));
                $this->assertNotSame(realpath($original), realpath($copy));
            }

            $this->assertFalse(is_link($app->path('core')));
            $this->assertFileExists($app->path('.arix.json'));
            $this->assertFileExists($app->path('app/bootstrap.php'));
            $this->assertDirectoryExists($app->path('storage/cache'));
        } finally {
            $app->cleanup();
        }
    }

    #[Test]
    public function it_keeps_fixture_source_and_other_runs_unchanged(): void
    {
        $first = new TestApplication();
        $second = new TestApplication();

        try {
            $source = dirname(__DIR__, 3);
            $original = file_get_contents($second->path('app/config/app.php'));
            $core = file_get_contents($second->path('core/autoload.php'));
            $first->write('app/config/app.php', '<?php throw new \\RuntimeException("private change");');
            $first->write('core/autoload.php', '<?php // private core change');
            $first->write('storage/cache/private.php', '<?php // private cache');
            $this->assertNotSame($first->root, $second->root);
            $this->assertSame($original, file_get_contents($second->path('app/config/app.php')));
            $this->assertSame($original, file_get_contents($source . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php'));
            $this->assertSame($core, file_get_contents($source . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php'));
            $this->assertFileDoesNotExist($second->path('storage/cache/private.php'));
            $first->cleanup();
            $this->assertDirectoryDoesNotExist($first->root);
            $this->assertDirectoryExists($second->root);
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }

    #[Test]
    public function it_isolates_the_common_application_views_and_translations(): void
    {
        $first = new TestApplication();
        $second = new TestApplication();

        try {
            $view = 'app/views/index.arix.php';
            $source = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'index.arix.php';
            $this->assertFileExists($source);
            $this->assertFileExists($second->path($view));
            $original = file_get_contents($source);
            $this->assertSame($original, file_get_contents($second->path($view)));

            $first->write($view, 'private view');

            $this->assertSame($original, file_get_contents($second->path($view)));
            $this->assertSame($original, file_get_contents($source));

            $catalog = 'app/lang/en.php';
            $catalogSource = dirname($source, 2) . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . 'en.php';
            $this->assertFileExists($catalogSource);
            $this->assertFileExists($second->path($catalog));
            $originalCatalog = file_get_contents($catalogSource);
            $this->assertSame($originalCatalog, file_get_contents($second->path($catalog)));

            $first->write($catalog, '<?php return [];');

            $this->assertSame($originalCatalog, file_get_contents($second->path($catalog)));
            $this->assertSame($originalCatalog, file_get_contents($catalogSource));
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }

    #[Test]
    public function it_boots_the_application_without_composer_and_keeps_requests_isolated(): void
    {
        $app = new TestApplication();
        $program = <<<'PHP'
require $argv[1] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
$kernel = require $argv[1] . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';
foreach ([1, 2] as $number) {
    $response = $kernel->handle(\Arix\HttpFoundation\Request::create('http://localhost/'));
    if ($response->getStatusCode() !== 200 || $response->getContent() !== 'Arix test application') { throw new RuntimeException($response->getContent()); }
    if ($response->headers->get('X-Fixture-Request') !== '1') { throw new RuntimeException('Request container leaked'); }
}
if (class_exists('Composer\\Autoload\\ClassLoader', false)) { throw new RuntimeException('Composer loaded'); }
if (class_exists('App\\Commands\\TestsCommand')) { throw new RuntimeException('CLI class leaked'); }
echo 'standalone';
PHP;

        try {
            $process = new Process([PHP_BINARY, '-r', $program, $app->root], $app->root, ['XDEBUG_MODE' => 'off']);
            $process->mustRun();
            $this->assertSame('standalone', $process->getOutput());
        } finally {
            $app->cleanup();
        }
    }
}
