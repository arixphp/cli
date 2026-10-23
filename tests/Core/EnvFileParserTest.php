<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Exceptions\CoreRuntimeException;
use Arix\Runtime\EnvFileParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempTree;

class EnvFileParserTest extends TestCase
{
    private TempTree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new TempTree();
    }

    protected function tearDown(): void
    {
        $this->tree->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_parses_literals_without_converting_or_interpolating_them(): void
    {
        $file = $this->tree->write('.env', <<<'ENV'
  # comment

APP_DEBUG = FALSE # disabled
APP_EMPTY=
APP_URL=https://example.test/path?q=a=b#fragment
APP_TEXT="false" # literal
APP_SINGLE='hello # world'
APP_REFERENCE=${APP_URL}
APP_DEBUG=true
OTHER_KEY=ignored-by-loader
ENV);

        $this->assertSame([
            'APP_DEBUG' => 'true',
            'APP_EMPTY' => '',
            'APP_URL' => 'https://example.test/path?q=a=b#fragment',
            'APP_TEXT' => '"false"',
            'APP_SINGLE' => "'hello # world'",
            'APP_REFERENCE' => '${APP_URL}',
            'OTHER_KEY' => 'ignored-by-loader',
        ], new EnvFileParser($file)->parse());
    }

    #[Test]
    #[DataProvider('invalidLines')]
    public function it_rejects_invalid_input_without_exposing_values(string $line): void
    {
        $file = $this->tree->write('.env', "APP_OK=true\n{$line}");

        try {
            new EnvFileParser($file)->parse();
            $this->fail('Invalid input must be rejected.');
        } catch (CoreRuntimeException $exception) {
            $this->assertStringContainsString('line 2', $exception->getMessage());
            $this->assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public static function invalidLines(): array
    {

        return [
            ['secret'], ['1KEY=secret'], ['export APP_KEY=secret'],
            ['APP_KEY="secret'], ["APP_KEY='secret"], ['APP_KEY="secret"garbage'],
            ["APP_KEY=secret\0"], ["APP_KEY=\"secret\nvalue\""],
        ];
    }

    #[Test]
    public function it_respects_backslash_parity_when_finding_the_closing_quote(): void
    {
        foreach ([1, 2, 3, 4] as $backslashes) {
            $slashes = str_repeat('\\', $backslashes);
            $ending = $backslashes % 2 === 0 ? '"' : '"inside"';
            $literal = "\"value{$slashes}{$ending}";
            $file = $this->tree->write('.env', "APP_KEY={$literal} # comment");
            $this->assertSame(['APP_KEY' => $literal], new EnvFileParser($file)->parse());
        }
    }

    #[Test]
    public function it_rejects_an_escaped_quote_without_a_real_closing_quote(): void
    {
        $file = $this->tree->write('.env', 'APP_KEY="value' . str_repeat('\\', 3) . '"');
        $this->expectException(CoreRuntimeException::class);
        new EnvFileParser($file)->parse();
    }

    #[Test]
    public function it_rejects_missing_files_and_directories(): void
    {
        $this->expectException(CoreRuntimeException::class);
        new EnvFileParser($this->tree->root)->parse();
    }

    #[Test]
    public function it_rejects_symbolic_links_including_dangling_links(): void
    {
        $target = $this->tree->write('target', 'APP_KEY=secret');
        $link = $this->tree->path('.env');

        if (! @symlink($target, $link)) {
            $this->markTestSkipped('Symbolic links are unavailable.');
        }

        foreach ([true, false] as $exists) {

            if (! $exists) {
                unlink($target);
            }

            try {
                new EnvFileParser($link)->parse();
                $this->fail('Linked environment files must be rejected.');
            } catch (CoreRuntimeException $exception) {
                $this->assertStringNotContainsString('secret', $exception->getMessage());
            }
        }
    }

    #[Test]
    public function it_rejects_unreadable_files_when_permissions_are_enforced(): void
    {
        $file = $this->tree->write('.env', 'APP_KEY=secret');
        chmod($file, 0000);
        clearstatcache(true, $file);

        try {

            if (is_readable($file)) {
                $this->markTestSkipped('The current account can read files despite mode 0000.');
            }

            $this->expectException(CoreRuntimeException::class);
            new EnvFileParser($file)->parse();
        } finally {
            chmod($file, 0600);
        }
    }
}
