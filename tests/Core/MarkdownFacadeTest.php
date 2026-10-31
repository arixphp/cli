<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Container\UnknownServiceException;
use Arix\Facades\Markdown;
use Arix\Markdown\DocumentReader;
use Arix\Markdown\Markdown as MarkdownService;
use BadMethodCallException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MarkdownFacadeTest extends TestCase
{
    #[Test]
    public function it_reads_and_renders_files_through_the_facade(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('arix-markdown-', true);
        mkdir($directory);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'hello.md', '# Hello');
        $container = new Container();
        $container->instance(MarkdownService::class, new MarkdownService(new DocumentReader($directory)));

        try {
            ContainerContext::run($container, function (): void {
                $this->assertSame('# Hello', Markdown::read('hello.md'));
                $this->assertSame('<h1>Hello</h1>', Markdown::html('hello.md'));
                $this->assertSame('<h1 id="hello">Hello</h1>', Markdown::render('hello.md')->html);
            });
        } finally {
            unlink($directory . DIRECTORY_SEPARATOR . 'hello.md');
            rmdir($directory);
        }
    }

    #[Test]
    public function it_converts_markdown_in_safe_mode(): void
    {
        $container = new Container();
        $container->instance(MarkdownService::class, new MarkdownService(new DocumentReader(sys_get_temp_dir())));

        ContainerContext::run($container, function (): void {
            $this->assertSame('<h1>Hello</h1>', Markdown::parse('# Hello'));
            $this->assertStringContainsString('&lt;script&gt;', Markdown::parse('<script>'));
        });
    }

    #[Test]
    public function it_resolves_the_current_service_without_caching(): void
    {
        $outer = new Container();
        $inner = new Container();
        $reader = new DocumentReader(sys_get_temp_dir());
        $outer->instance(MarkdownService::class, new MarkdownService($reader));
        $inner->instance(MarkdownService::class, new class($reader) extends MarkdownService
        {
            public function parse(string $markdown): string
            {
                return 'replacement';
            }
        });

        ContainerContext::run($outer, function () use ($inner): void {
            $this->assertSame('<h1>Hello</h1>', Markdown::parse('# Hello'));
            ContainerContext::run($inner, function (): void {
                $this->assertSame('replacement', Markdown::parse('# Hello'));
            });
            $this->assertSame('<h1>Hello</h1>', Markdown::parse('# Hello'));
        });
    }

    #[Test]
    public function it_rejects_unknown_methods(): void
    {
        $container = new Container();
        $container->instance(MarkdownService::class, new MarkdownService(new DocumentReader(sys_get_temp_dir())));

        $this->expectException(BadMethodCallException::class);
        ContainerContext::run($container, fn () => Markdown::unknown());
    }

    #[Test]
    public function it_requires_a_registered_service(): void
    {
        $this->expectException(UnknownServiceException::class);
        ContainerContext::run(new Container(), fn () => Markdown::parse('Hello'));
    }

    #[Test]
    public function it_requires_an_active_container(): void
    {
        $this->expectException(LogicException::class);
        Markdown::parse('Hello');
    }
}
