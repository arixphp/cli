<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\InstallationTarget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\NewApplicationFixture;

class InstallationTargetTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_resolves_the_parent_from_the_invocation_directory(): void
    {
        mkdir($this->fixture->path('projects'));
        $this->fixture->write('existing.txt', 'keep');
        $target = InstallationTarget::resolve('my-app', '.', $this->fixture->root);
        $this->assertSame($this->fixture->path('my-app'), $target->path);
        $this->assertNull($target->identity);
        $this->assertSame($this->fixture->path('projects/app'), InstallationTarget::resolve('app', 'projects', $this->fixture->root)->path);
        $this->assertSame($this->fixture->path('app'), InstallationTarget::resolve('app', '..', $this->fixture->path('projects'))->path);
        $this->assertSame($this->fixture->path('projects/app'), InstallationTarget::resolve('app', $this->fixture->path('projects'), $this->fixture->root)->path);
    }

    #[Test]
    public function it_accepts_existing_empty_directories(): void
    {
        mkdir($this->fixture->path('app'));
        $target = InstallationTarget::resolve('app', '.', $this->fixture->root);
        $this->assertNotNull($target->identity);
        $target->assertUnchanged();
    }

    #[Test]
    #[DataProvider('invalidNames')]
    public function it_rejects_nonportable_names(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        InstallationTarget::resolve($name, '.', $this->fixture->root);
    }

    public static function invalidNames(): array
    {

        return array_map(static fn (string $name): array => [$name], ['', '.', '..', '../app', 'a/b', 'a\\b', '-app', 'a b', "a\0b", 'app.', 'cOn', 'LPT9', 'COM1', 'tïenda', str_repeat('a', 101)]);
    }

    #[Test]
    public function it_rejects_hidden_content_without_modifying_it(): void
    {
        $path = $this->fixture->write('app/.gitignore', 'keep');

        try {
            InstallationTarget::resolve('app', '.', $this->fixture->root);
            $this->fail('Occupied destination accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame('keep', file_get_contents($path));
        }
    }

    #[Test]
    public function it_rejects_a_file_as_destination(): void
    {
        $this->fixture->write('app', 'keep');
        $this->expectException(InvalidArgumentException::class);
        InstallationTarget::resolve('app', '.', $this->fixture->root);
    }

    #[Test]
    public function it_rejects_broken_destination_links(): void
    {
        symlink($this->fixture->path('absent'), $this->fixture->path('app'));
        $this->expectException(InvalidArgumentException::class);
        InstallationTarget::resolve('app', '.', $this->fixture->root);
    }

    #[Test]
    public function it_resolves_parent_links_but_rejects_destination_links(): void
    {
        mkdir($this->fixture->path('projects'));
        symlink($this->fixture->path('projects'), $this->fixture->path('alias'));
        $this->assertSame($this->fixture->path('projects/app'), InstallationTarget::resolve('app', 'alias', $this->fixture->root)->path);
        symlink($this->fixture->path('projects'), $this->fixture->path('app'));
        $this->expectException(InvalidArgumentException::class);
        InstallationTarget::resolve('app', '.', $this->fixture->root);
    }

    #[Test]
    public function it_rejects_missing_parents_without_creating_them(): void
    {
        $this->expectException(InvalidArgumentException::class);
        InstallationTarget::resolve('app', 'absent', $this->fixture->root);
    }

    #[Test]
    public function it_detects_a_destination_created_after_validation(): void
    {
        $target = InstallationTarget::resolve('app', '.', $this->fixture->root);
        mkdir($target->path);
        $this->expectException(InvalidArgumentException::class);
        $target->assertUnchanged();
    }

    #[Test]
    public function it_detects_content_added_to_an_empty_destination(): void
    {
        mkdir($this->fixture->path('app'));
        $target = InstallationTarget::resolve('app', '.', $this->fixture->root);
        $this->fixture->write('app/new.txt', 'keep');
        $this->expectException(InvalidArgumentException::class);
        $target->assertUnchanged();
    }

    #[Test]
    public function it_rejects_unwritable_parents(): void
    {
        mkdir($this->fixture->path('locked'), 0500);

        try {
            if (is_writable($this->fixture->path('locked'))) {
                $this->markTestSkipped('This user bypasses filesystem permissions.');
            }

            $this->expectException(InvalidArgumentException::class);
            InstallationTarget::resolve('app', 'locked', $this->fixture->root);
        } finally {
            chmod($this->fixture->path('locked'), 0700);
        }
    }
}
