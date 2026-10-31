<?php

declare(strict_types=1);

namespace Tests\Core\Markdown;

use Arix\Markdown\DocumentReader;
use Arix\Markdown\Markdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestApplication;

class MarkdownTest extends TestCase
{
    #[Test]
    public function it_reads_and_renders_a_document_using_the_new_api(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('arix-markdown-', true);
        mkdir($directory);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'hello.md', '# Hello');

        try {
            $markdown = new Markdown(new DocumentReader($directory));
            $this->assertSame('# Hello', $markdown->read('hello.md'));
            $this->assertSame('<h1>Hello</h1>', $markdown->parse('# Hello'));
            $this->assertSame('<h1>Hello</h1>', $markdown->html('hello.md'));
            $this->assertSame('<h1 id="hello">Hello</h1>', $markdown->render('hello.md')->html);
            $this->assertSame('hello', $markdown->render('hello.md')->headings[0]->id);
        } finally {
            unlink($directory . DIRECTORY_SEPARATOR . 'hello.md');
            rmdir($directory);
        }
    }

    #[Test]
    public function it_rejects_invalid_paths_for_every_file_method(): void
    {
        $markdown = new Markdown(new DocumentReader(sys_get_temp_dir()));

        foreach (['read', 'html', 'render'] as $method) {
            try {
                $markdown->{$method}('../outside.md');
                $this->fail("{$method} accepted an invalid path");
            } catch (RuntimeException $exception) {
                $this->assertSame('Invalid Markdown document name.', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_renders_markdown_in_safe_mode(): void
    {
        $markdown = new Markdown(new DocumentReader(sys_get_temp_dir()));
        $this->assertSame('<h1>Hello</h1>', $markdown->parse('# Hello'));
        $this->assertStringNotContainsString('<script>', $markdown->parse('<script>alert(1)</script>'));
        $this->assertStringNotContainsString('href="javascript:', $markdown->parse('[link](javascript:alert)'));
    }

    #[Test]
    public function it_renders_without_composer_in_a_fresh_process(): void
    {
        $autoload = TestApplication::shared()->path('core/autoload.php');
        $program = 'require ' . var_export($autoload, true) . '; if (class_exists("Composer\\Autoload\\ClassLoader", false)) { exit(2); } echo (new Arix\\Markdown\\Markdown(new Arix\\Markdown\\DocumentReader(sys_get_temp_dir())))->parse("# Hello");';
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('<h1>Hello</h1>', $output);
    }
}
