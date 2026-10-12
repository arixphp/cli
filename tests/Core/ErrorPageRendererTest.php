<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Http\ErrorPageRenderer;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoreUpdateFixture;

class ErrorPageRendererTest extends TestCase
{
    #[Test]
    public function it_renders_the_managed_error_template(): void
    {
        $html = new ErrorPageRenderer()->render(404, 'Page not found.');

        $this->assertNotNull($html);
        $this->assertStringStartsWith('<!doctype html>', $html);
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<title>404 — ArixPHP</title>', $html);
        $this->assertStringContainsString('<h1>404</h1>', $html);
        $this->assertStringContainsString('<pre>Page not found.</pre>', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    #[Test]
    public function it_escapes_error_messages_without_recursive_substitution(): void
    {
        $html = new ErrorPageRenderer()->render(400, '<script>"x" & \'y\'</script> {{status}}');

        $this->assertNotNull($html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;&quot;x&quot; &amp; &#039;y&#039;&lt;/script&gt; {{status}}', $html);
    }

    #[Test]
    public function it_substitutes_invalid_message_encoding_safely(): void
    {
        $html = new ErrorPageRenderer()->render(400, "invalid \xff");

        $this->assertNotNull($html);
        $this->assertStringContainsString("invalid \xef\xbf\xbd", $html);
    }

    #[Test]
    #[DataProvider('unusableResources')]
    public function it_returns_null_when_the_resource_cannot_be_used(string $kind, string $contents): void
    {
        $fixture = new CoreUpdateFixture();
        $path = $fixture->path('error.html');

        if ($kind === 'directory') {
            mkdir($path);
        } elseif ($kind !== 'missing') {
            $fixture->write('error.html', $contents);
        }

        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $this->assertNull(new ErrorPageRenderer($path)->render(500, 'Internal server error.'));
        } finally {
            restore_error_handler();
            $fixture->cleanup();
        }
    }

    public static function unusableResources(): array
    {

        return [
            ['missing', ''],
            ['directory', ''],
            ['empty', ''],
            ['invalid UTF-8', "{{status}}{{name}}{{message}}{{locale}}\xff"],
            ['missing status', '{{name}}{{message}}{{locale}}'],
            ['missing name', '{{status}}{{message}}{{locale}}'],
            ['missing message', '{{status}}{{name}}{{locale}}'],
            ['missing locale', '{{status}}{{name}}{{message}}'],
        ];
    }

    #[Test]
    public function it_returns_null_for_an_unreadable_resource(): void
    {
        $fixture = new CoreUpdateFixture();
        $path = $fixture->write('error.html', '{{status}}{{name}}{{message}}{{locale}}');
        chmod($path, 0000);

        try {
            if (is_readable($path)) {
                $this->markTestSkipped('This filesystem/user does not enforce unreadable fixture permissions.');
            }

            $this->assertNull(new ErrorPageRenderer($path)->render(500, 'Internal server error.'));
        } finally {
            chmod($path, 0600);
            $fixture->cleanup();
        }
    }

    #[Test]
    public function it_reads_the_resource_again_after_a_previous_failure(): void
    {
        $fixture = new CoreUpdateFixture();
        $renderer = new ErrorPageRenderer($fixture->path('error.html'));

        try {
            $this->assertNull($renderer->render(404, 'Missing.'));
            $fixture->write('error.html', '{{locale}} {{name}} {{status}} {{message}}');

            $this->assertSame('en ArixPHP 404 Missing.', $renderer->render(404, 'Missing.'));
        } finally {
            $fixture->cleanup();
        }
    }
}
