<?php

declare(strict_types=1);

namespace Tests\Core\Markdown;

use Arix\Markdown\HeadingParser;
use Arix\Markdown\RenderedMarkdown;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

class HeadingParserTest extends TestCase
{
    #[Test]
    public function it_renders_headings_and_metadata(): void
    {
        $result = $this->render("# Hello\n\n## Define a Route {#route}");

        $this->assertSame("<h1 id=\"hello\">Hello</h1>\n<h2 id=\"route\">Define a Route</h2>", $result->html);
        $this->assertSame([[1, 'hello', 'Hello'], [2, 'route', 'Define a Route']], array_map(fn ($heading) => [$heading->level, $heading->id, $heading->title], $result->headings));
    }

    #[Test]
    public function it_reserves_explicit_ids_before_automatic_ids(): void
    {
        $result = $this->render("## Intro\n\n## Intro\n\n## Other {#intro}");

        $this->assertSame(['intro-2', 'intro-3', 'intro'], array_map(fn ($heading) => $heading->id, $result->headings));
    }

    #[Test]
    public function it_rejects_duplicate_explicit_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->render("## One {#same}\n\n## Two {#same}");
    }

    #[Test]
    public function it_handles_nested_and_formatted_headings(): void
    {
        $source = "Title\n=====\n\n> ## **Bold** [link](https://example.com) `code` ![alt](image.png) &amp;\n\n- ### Nested\n\n```md\n# Not a heading\n```";
        $result = $this->render($source);

        $this->assertSame(['title', 'bold-link-code-alt', 'nested'], array_map(fn ($heading) => $heading->id, $result->headings));
        $this->assertSame('Bold link code alt &', $result->headings[1]->title);
        $this->assertStringContainsString('<h2 id="bold-link-code-alt">', $result->html);
    }

    #[Test]
    public function it_generates_unicode_and_empty_slugs_and_isolates_renders(): void
    {
        $result = $this->render("## Été & café!\n\n## !!!\n\n## !!!");

        $this->assertSame(['été-café', 'section', 'section-2'], array_map(fn ($heading) => $heading->id, $result->headings));
        $this->assertSame('section', $this->render('## !!!')->headings[0]->id);
    }

    #[Test]
    public function it_rejects_invalid_explicit_ids(): void
    {
        foreach (['{#}', '{#bad id}', '{#x" onclick="x}'] as $id) {
            try {
                $this->render("## Heading {$id}");
                $this->fail("Accepted invalid ID {$id}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    #[Test]
    public function it_preserves_safe_mode(): void
    {
        $result = $this->render("## <script>alert(1)</script>\n\n[bad](javascript:alert)");

        $this->assertStringNotContainsString('<script>', $result->html);
        $this->assertStringNotContainsString('href="javascript:', $result->html);
    }

    #[Test]
    public function it_supports_explicit_setext_ids(): void
    {
        $result = $this->render("Heading {#stable}\n=======");

        $this->assertSame('<h1 id="stable">Heading</h1>', $result->html);
        $this->assertSame('Heading', $result->headings[0]->title);
    }

    #[Test]
    public function it_renders_without_composer(): void
    {
        $autoload = TestApplication::shared()->path('core/autoload.php');
        $program = 'require ' . var_export($autoload, true) . '; if (class_exists("Composer\\Autoload\\ClassLoader", false)) { exit(2); } $p = new Arix\\Markdown\\HeadingParser(); $p->setSafeMode(true); $r = $p->renderDocument("# Hello"); echo $r->html . "|" . $r->headings[0]->id;';
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $errors);
        $this->assertSame('<h1 id="hello">Hello</h1>|hello', $output);
    }

    private function render(string $source): RenderedMarkdown
    {
        $parser = new HeadingParser();
        $parser->setSafeMode(true);

        return $parser->renderDocument($source);
    }
}
