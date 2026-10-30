<?php

declare(strict_types=1);

namespace Tests\Core\Markdown;

use Arix\Markdown\Markdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

class MarkdownTest extends TestCase
{
    #[Test]
    public function it_renders_markdown_in_safe_mode(): void
    {
        $markdown = new Markdown();
        $this->assertSame('<h1>Hello</h1>', $markdown->toHtml('# Hello'));
        $this->assertStringNotContainsString('<script>', $markdown->toHtml('<script>alert(1)</script>'));
        $this->assertStringNotContainsString('href="javascript:', $markdown->toHtml('[link](javascript:alert)'));
    }

    #[Test]
    public function it_renders_without_composer_in_a_fresh_process(): void
    {
        $autoload = TestApplication::shared()->path('core/autoload.php');
        $program = 'require ' . var_export($autoload, true) . '; if (class_exists("Composer\\Autoload\\ClassLoader", false)) { exit(2); } echo (new Arix\\Markdown\\Markdown())->toHtml("# Hello");';
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('<h1>Hello</h1>', $output);
    }
}
