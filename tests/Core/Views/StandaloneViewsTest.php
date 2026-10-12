<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StandaloneViewsTest extends TestCase
{
    #[Test]
    public function it_resolves_relocated_views_and_integration_without_composer(): void
    {
        $root = dirname(__DIR__, 3);
        $autoload = $root.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'autoload.php';
        $program = 'require '.var_export($autoload, true).'; '.<<<'PHP'
            if (class_exists('Composer\Autoload\ClassLoader', false)) { exit(2); }
            $files = [];
            foreach (['Arix\Views\Contracts\View', 'Arix\Views\View', 'Arix\Views\ViewFactory', 'Arix\BladeOne\BladeOne', 'Arix\Views\TemplateEngine'] as $class) {
                $files[$class] = (new ReflectionClass($class))->getFileName();
            }
            foreach (['view', 'trans', 'url', 'config', 'asset'] as $helper) {
                if (! function_exists($helper)) { exit(3); }
            }
            echo json_encode($files);
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        $files = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($root.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'Views'.DIRECTORY_SEPARATOR.'Contracts'.DIRECTORY_SEPARATOR.'View.php', $files['Arix\Views\Contracts\View']);
        $this->assertSame($root.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'Views'.DIRECTORY_SEPARATOR.'View.php', $files['Arix\Views\View']);
        $this->assertSame($root.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'Views'.DIRECTORY_SEPARATOR.'ViewFactory.php', $files['Arix\Views\ViewFactory']);
        $this->assertSame($root.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'BladeOne'.DIRECTORY_SEPARATOR.'BladeOne.php', $files['Arix\BladeOne\BladeOne']);
        $this->assertSame($root.DIRECTORY_SEPARATOR.'core'.DIRECTORY_SEPARATOR.'Views'.DIRECTORY_SEPARATOR.'TemplateEngine.php', $files['Arix\Views\TemplateEngine']);
    }
}
