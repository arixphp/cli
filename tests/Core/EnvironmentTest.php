<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Exceptions\CoreRuntimeException;
use Arix\Runtime\Environment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    public function it_loads_all_keys_without_overwriting_existing_environment(): void
    {
        $this->tree->write('.env', "ARIX_ENV_NEW=from-file\nARIX_ENV_EXISTING=file\nARIX_ENV_PROCESS=file\nARIX_ENV_EMPTY=file\n");
        unset($_ENV['ARIX_ENV_NEW'], $_ENV['ARIX_ENV_PROCESS']);
        $_ENV['ARIX_ENV_EXISTING'] = 'existing';
        $_ENV['ARIX_ENV_EMPTY'] = '';
        putenv('ARIX_ENV_PROCESS=process');

        try {
            Environment::load($this->tree->root);
            $this->assertSame('from-file', $_ENV['ARIX_ENV_NEW']);
            $this->assertSame('existing', $_ENV['ARIX_ENV_EXISTING']);
            $this->assertSame('', $_ENV['ARIX_ENV_EMPTY']);
            $this->assertArrayNotHasKey('ARIX_ENV_PROCESS', $_ENV);
            $this->assertSame('process', getenv('ARIX_ENV_PROCESS'));
        } finally {
            putenv('ARIX_ENV_PROCESS');
        }
    }

    #[Test]
    public function it_keeps_an_existing_null_value_and_loads_an_optional_missing_file(): void
    {
        Environment::load($this->tree->root);
        $_ENV['ARIX_ENV_NULL'] = null;
        $this->tree->write('.env', 'ARIX_ENV_NULL=file');
        Environment::load($this->tree->root);
        $this->assertArrayHasKey('ARIX_ENV_NULL', $_ENV);
        $this->assertNull($_ENV['ARIX_ENV_NULL']);
    }

    #[Test]
    public function it_does_not_apply_any_values_from_a_malformed_file(): void
    {
        $this->tree->write('.env', "APP_SCOPE_NEW=secret\ninvalid secret");
        $before = $_ENV;

        try {
            Environment::load($this->tree->root);
            $this->fail('Malformed files must fail before callback execution.');
        } catch (CoreRuntimeException) {
            $this->assertSame($before, $_ENV);
        }
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
