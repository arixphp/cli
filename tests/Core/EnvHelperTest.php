<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApplication;

class EnvHelperTest extends TestCase
{
    #[Test]
    #[DataProvider('environmentValues')]
    public function it_preserves_typed_values_and_normalizes_unquoted_literals(mixed $value, mixed $expected): void
    {
        $program = '$_ENV["ARIX_TEST_ENV"] = ' . var_export($value, true) . '; ' . <<<'PHP'
            $calls = 0;
            $value = env('ARIX_TEST_ENV', static function () use (&$calls): string {
                $calls++;
                return 'fallback';
            });
            echo json_encode([$value, $calls], JSON_THROW_ON_ERROR);
            PHP;

        $this->assertSame([$expected, 0], $this->runStandalone($program));
    }

    public static function environmentValues(): array
    {

        return [
            ['true', true],
            ['false', false],
            ['1', '1'],
            ['0', '0'],
            ['', ''],
            [false, false],
            [0, 0],
            [1, 1],
            [1.5, 1.5],
            [[], []],
            [null, null],
            ['TRUE', true],
            [' false ', false],
        ];
    }

    #[Test]
    #[DataProvider('defaultValues')]
    public function it_accepts_literal_defaults_for_missing_variables(mixed $default): void
    {
        $program = 'unset($_ENV["ARIX_TEST_ENV"]); putenv("ARIX_TEST_ENV"); echo json_encode(env("ARIX_TEST_ENV", ' . var_export($default, true) . '), JSON_THROW_ON_ERROR);';

        $this->assertSame($default, $this->runStandalone($program));
    }

    public static function defaultValues(): array
    {

        return [[null], ['fallback'], [false], [0], [1.5], [['enabled' => true]]];
    }

    #[Test]
    public function it_evaluates_a_closure_only_when_the_variable_is_missing(): void
    {
        $result = $this->runStandalone(<<<'PHP'
            unset($_ENV['ARIX_TEST_ENV']);
            putenv('ARIX_TEST_ENV');
            $calls = 0;
            $value = env('ARIX_TEST_ENV', static function () use (&$calls): array {
                $calls++;
                return ['enabled' => true];
            });
            echo json_encode([$value, $calls], JSON_THROW_ON_ERROR);
            PHP);

        $this->assertSame([['enabled' => true], 1], $result);
    }

    #[Test]
    public function it_reads_process_environment_when_the_superglobal_key_is_missing(): void
    {
        $result = $this->runStandalone(<<<'PHP'
            unset($_ENV['ARIX_TEST_ENV']);
            putenv('ARIX_TEST_ENV=0');
            $zero = env('ARIX_TEST_ENV');
            putenv('ARIX_TEST_ENV=false');
            $boolean = env('ARIX_TEST_ENV');
            putenv('ARIX_TEST_ENV=');
            $empty = env('ARIX_TEST_ENV');
            $_ENV['ARIX_TEST_ENV'] = 'superglobal';
            echo json_encode([$zero, $boolean, $empty, env('ARIX_TEST_ENV')], JSON_THROW_ON_ERROR);
            PHP);

        $this->assertSame(['0', false, '', 'superglobal'], $result);
    }

    private function runStandalone(string $program): mixed
    {
        $autoload = TestApplication::shared()->path('core/autoload.php');
        $program = 'require ' . var_export($autoload, true) . '; if (class_exists("Composer\\Autoload\\ClassLoader", false)) { exit(2); } ' . $program;
        $process = proc_open([PHP_BINARY, '-r', $program], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
