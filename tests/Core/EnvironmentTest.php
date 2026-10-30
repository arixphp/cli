<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Exceptions\CoreRuntimeException;
use Arix\Runtime\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TempTree;

class EnvironmentTest extends TestCase
{
    private TempTree $tree;

    private array $env;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new TempTree();
        $this->env = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->env;
        $this->tree->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_overlays_only_app_keys_and_restores_original_values(): void
    {
        $_ENV['APP_SCOPE_TEST'] = null;
        unset($_ENV['APP_SCOPE_NEW']);
        $this->tree->write('.env', "APP_SCOPE_TEST=file\nAPP_SCOPE_NEW=created\nPATH=forbidden\nAPP_SCOPE_TEST=last");
        $before = $_ENV;
        $process = getenv('APP_SCOPE_TEST');

        $result = Environment::run($this->tree->root, function () use ($process): string {
            $this->assertSame('last', $_ENV['APP_SCOPE_TEST']);
            $this->assertSame('created', $_ENV['APP_SCOPE_NEW']);
            $this->assertNotSame('forbidden', $_ENV['PATH'] ?? null);
            $this->assertSame($process, getenv('APP_SCOPE_TEST'));

            return 'result';
        });

        $this->assertSame('result', $result);
        $this->assertSame($before, $_ENV);
    }

    #[Test]
    public function it_restores_on_failure_and_rereads_later_changes_and_removal(): void
    {
        unset($_ENV['APP_SCOPE_NEW']);
        $file = $this->tree->write('.env', 'APP_SCOPE_NEW=first');

        try {
            Environment::run($this->tree->root, static function (): never {
                throw new RuntimeException('callback failed');
            });
            $this->fail('Callback failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('APP_SCOPE_NEW', $_ENV);
        file_put_contents($file, 'APP_SCOPE_NEW=second');
        $this->assertSame('second', Environment::run($this->tree->root, static fn () => $_ENV['APP_SCOPE_NEW']));
        unlink($file);
        $this->assertNull(Environment::run($this->tree->root, static fn () => $_ENV['APP_SCOPE_NEW'] ?? null));
    }

    #[Test]
    public function it_reuses_same_root_scopes_without_reloading_and_rejects_other_roots(): void
    {
        $file = $this->tree->write('.env', 'APP_SCOPE_NEW=first');
        mkdir($this->tree->path('other'));

        Environment::run($this->tree->root, function () use ($file): void {
            file_put_contents($file, 'invalid assignment');
            $this->assertSame('first', Environment::run($this->tree->root, static fn () => $_ENV['APP_SCOPE_NEW']));

            try {
                Environment::run($this->tree->path('other'), static fn () => null);
                $this->fail('Overlapping applications must be rejected.');
            } catch (CoreRuntimeException) {
                $this->assertSame('first', $_ENV['APP_SCOPE_NEW']);
            }
        });
    }

    #[Test]
    public function it_does_not_apply_any_values_from_a_malformed_file(): void
    {
        $this->tree->write('.env', "APP_SCOPE_NEW=secret\ninvalid secret");
        $before = $_ENV;

        try {
            Environment::run($this->tree->root, static fn () => null);
            $this->fail('Malformed files must fail before callback execution.');
        } catch (CoreRuntimeException) {
            $this->assertSame($before, $_ENV);
        }
    }

    #[Test]
    public function it_does_not_treat_a_dangling_link_as_an_absent_optional_file(): void
    {
        if (! @symlink($this->tree->path('missing'), $this->tree->path('.env'))) {
            $this->markTestSkipped('Symbolic links are unavailable.');
        }

        $this->expectException(CoreRuntimeException::class);
        Environment::run($this->tree->root, static fn () => null);
    }

    #[Test]
    #[DataProvider('debugValues')]
    public function it_resolves_debug_using_a_strict_allowlist(mixed $value, bool $expected): void
    {
        $_ENV['APP_DEBUG'] = $value;
        $this->assertSame($expected, Environment::debug());
    }

    public static function debugValues(): array
    {

        return [[true, true], [1, true], ['1', true], ['TRUE', true], [false, false],
            ['false', false], ['"false"', false], ['invalid', false], ['', false], [null, false], [2, false]];
    }
}
