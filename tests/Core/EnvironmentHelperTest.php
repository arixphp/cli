<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestApplication;

class EnvironmentHelperTest extends TestCase
{
    #[Test]
    #[DataProvider('literals')]
    public function it_normalizes_literals_without_coercing_quoted_or_numeric_strings(mixed $literal, mixed $expected): void
    {
        $this->assertSame($expected, $this->evaluate('$_ENV["APP_HELPER_TEST"] = ' . var_export($literal, true) . '; return env("APP_HELPER_TEST", "fallback");'));
    }

    public static function literals(): array
    {

        return [
            [' TRUE ', true], ['FALSE', false], ['null', null], [null, null],
            [false, false], ['', ''], ['1', '1'], ['0', '0'], ['123', '123'],
            ['"false"', 'false'], ["'true'", 'true'], ['"null"', 'null'],
            ['"a\\n\\r\\t"', "a\n\r\t"],
            ['"a\\"b"', 'a"b'], ['"a\\\\b"', 'a\\b'], ['"a\\q"', 'a\\q'],
            ["'a\\n'", 'a\\n'], ['${APP_OTHER}', '${APP_OTHER}'],
        ];
    }

    #[Test]
    public function it_prefers_env_over_process_values_and_evaluates_defaults_only_for_absence(): void
    {
        $this->assertSame(['process', null, 'fallback', 1], $this->evaluate(<<<'PHP'
unset($_ENV['APP_HELPER_TEST']);
putenv('APP_HELPER_TEST=process');
$calls = 0;
$default = static function () use (&$calls) { $calls++; return 'fallback'; };
$process = env('APP_HELPER_TEST', $default);
$_ENV['APP_HELPER_TEST'] = null;
$null = env('APP_HELPER_TEST', $default);
unset($_ENV['APP_HELPER_TEST']);
putenv('APP_HELPER_TEST');
$missing = env('APP_HELPER_TEST', $default);
return [$process, $null, $missing, $calls];
PHP));
    }

    private function evaluate(string $body): mixed
    {
        $autoload = TestApplication::shared()->path('core/autoload.php');
        $program = 'require $argv[1]; $result = (static function () {' . $body . '})(); echo json_encode($result, JSON_THROW_ON_ERROR);';
        $process = new Process([PHP_BINARY, '-r', $program, $autoload], env: ['XDEBUG_MODE' => 'off']);
        $process->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
