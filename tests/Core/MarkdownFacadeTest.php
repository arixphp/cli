<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Container\Container;
use Arix\Container\ContainerContext;
use Arix\Container\UnknownServiceException;
use Arix\Facades\Markdown;
use Arix\Markdown\Markdown as MarkdownService;
use BadMethodCallException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MarkdownFacadeTest extends TestCase
{
    #[Test]
    public function it_converts_markdown_in_safe_mode(): void
    {
        $container = new Container();
        $container->instance(MarkdownService::class, new MarkdownService());

        ContainerContext::run($container, function (): void {
            $this->assertSame('<h1>Hello</h1>', Markdown::toHtml('# Hello'));
            $this->assertStringContainsString('&lt;script&gt;', Markdown::toHtml('<script>'));
        });
    }

    #[Test]
    public function it_resolves_the_current_service_without_caching(): void
    {
        $outer = new Container();
        $inner = new Container();
        $outer->instance(MarkdownService::class, new MarkdownService());
        $inner->instance(MarkdownService::class, new class() extends MarkdownService
        {
            public function toHtml(string $markdown): string
            {
                return 'replacement';
            }
        });

        ContainerContext::run($outer, function () use ($inner): void {
            $this->assertSame('<h1>Hello</h1>', Markdown::toHtml('# Hello'));
            ContainerContext::run($inner, function (): void {
                $this->assertSame('replacement', Markdown::toHtml('# Hello'));
            });
            $this->assertSame('<h1>Hello</h1>', Markdown::toHtml('# Hello'));
        });
    }

    #[Test]
    public function it_rejects_unknown_methods(): void
    {
        $container = new Container();
        $container->instance(MarkdownService::class, new MarkdownService());

        $this->expectException(BadMethodCallException::class);
        ContainerContext::run($container, fn () => Markdown::unknown());
    }

    #[Test]
    public function it_requires_a_registered_service(): void
    {
        $this->expectException(UnknownServiceException::class);
        ContainerContext::run(new Container(), fn () => Markdown::toHtml('Hello'));
    }

    #[Test]
    public function it_requires_an_active_container(): void
    {
        $this->expectException(LogicException::class);
        Markdown::toHtml('Hello');
    }
}
