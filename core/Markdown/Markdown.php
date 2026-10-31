<?php

declare(strict_types=1);

namespace Arix\Markdown;

use Arix\Parsedown\Parsedown;

class Markdown
{
    public function render(string $markdown): RenderedMarkdown
    {
        $parser = new HeadingParser();
        $parser->setSafeMode(true);

        return $parser->renderDocument($markdown);
    }

    public function toHtml(string $markdown): string
    {
        $parser = new Parsedown();
        $parser->setSafeMode(true);

        return $parser->text($markdown);
    }
}
