<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use Arix\Views\TemplateResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TemplateResolverTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-views-' . bin2hex(random_bytes(8));
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'layouts', 0755, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'main.arix.php', 'layout');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'plain.php', 'plain');
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_resolves_dotted_names_to_confined_files(): void
    {
        $resolver = new TemplateResolver($this->directory);

        $this->assertSame($this->directory . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'main.arix.php', $resolver->resolve('layouts.main'));
        $this->assertSame($this->directory . DIRECTORY_SEPARATOR . 'plain.php', $resolver->resolve('plain'));
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_invalid_template_names(string $name): void
    {
        $this->expectException(RuntimeException::class);
        (new TemplateResolver($this->directory))->resolve($name);
    }

    public static function invalidNames(): array
    {
        return [
            [''],
            ['..'],
            ['../secret'],
            ['layouts/../secret'],
            ['layouts/main'],
            ['/absolute'],
            ['a..b'],
            ['a b'],
            ['.leading'],
            ['trailing.'],
            ['a*b'],
        ];
    }

    #[Test]
    public function it_rejects_missing_templates(): void
    {
        $this->expectException(RuntimeException::class);
        (new TemplateResolver($this->directory))->resolve('missing');
    }

    #[Test]
    public function it_rejects_conflicting_templates(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'dupe.arix.php', 'a');
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'dupe.php', 'b');

        $this->expectException(RuntimeException::class);
        (new TemplateResolver($this->directory))->resolve('dupe');
    }

    #[Test]
    public function it_rejects_symlinks_case_mismatches_and_escapes(): void
    {
        $outside = $this->directory . DIRECTORY_SEPARATOR . 'outside.php';
        file_put_contents($outside, 'outside');
        symlink($outside, $this->directory . DIRECTORY_SEPARATOR . 'linked.arix.php');

        $resolver = new TemplateResolver($this->directory);

        try {
            $resolver->resolve('linked');
            $this->fail('Symlinked templates must be rejected.');
        } catch (RuntimeException) {
        }

        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'Cased.arix.php', 'cased');

        try {
            $resolver->resolve('cased');
            $this->fail('Case mismatches must be rejected.');
        } catch (RuntimeException) {
        }

        $this->assertSame($this->directory . DIRECTORY_SEPARATOR . 'Cased.arix.php', $resolver->resolve('Cased'));
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . DIRECTORY_SEPARATOR . $entry);
            }

            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
