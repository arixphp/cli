<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Runtime\ConsoleRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\StandaloneCore;

class ConsoleRuntimeTest extends TestCase
{
    #[Test]
    public function it_pins_lazy_schedule_code_and_logs_when_the_deployment_alias_changes(): void
    {
        $root = $this->application([]);
        $alias = $root . DIRECTORY_SEPARATOR . 'current';
        $other = $root . DIRECTORY_SEPARATOR . 'other';
        mkdir($other);
        symlink($root, $alias);
        $id = 'Pinned' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . "{$id}.php", "<?php declare(strict_types=1); namespace App\\Services; class {$id} { public static function run(): void {} }");
        file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'schedules.php', '<?php declare(strict_types=1); unlink(' . var_export($alias, true) . '); symlink(' . var_export($other, true) . ', ' . var_export($alias, true) . '); return static function ($schedule): void { $schedule->call("pinned", static fn () => \\App\\Services\\' . $id . '::run())->everyMinute(); };');
        $autoload = StandaloneCore::autoload($root);
        $program = 'require $argv[1]; exit((new Arix\\Runtime\\ConsoleRuntime())->run($argv[2]));';
        $process = new Process([PHP_BINARY, '-r', $program, $autoload, $alias]);

        try {
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertFileExists($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'cron.log');
            $this->assertDirectoryDoesNotExist($other . DIRECTORY_SEPARATOR . 'storage');
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    #[Test]
    public function it_autoloads_dependencies_when_a_scheduled_callback_executes(): void
    {
        $id = 'Task' . bin2hex(random_bytes(6));
        $root = $this->application([
            'autoload' => "static function (): void { \\App\\Services\\{$id}::run(); }",
        ]);
        mkdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Services' . DIRECTORY_SEPARATOR . "{$id}.php", "<?php\ndeclare(strict_types=1);\nnamespace App\\Services;\nclass {$id} { public static function run(): void { \$GLOBALS['arix_autoload_task'] = 'executed'; } }\n");
        $GLOBALS['arix_autoload_task'] = null;

        try {
            $this->assertSame(0, (new ConsoleRuntime())->run($root));
            $this->assertSame('executed', $GLOBALS['arix_autoload_task']);
        } finally {
            unset($GLOBALS['arix_autoload_task']);
            (new Filesystem())->remove($root);
        }
    }

    #[Test]
    public function it_runs_the_application_schedule_and_reports_the_outcome(): void
    {
        $root = $this->application([
            'report:daily' => 'static function (): void { $GLOBALS["arix_task_ran"] = true; }',
        ]);
        $GLOBALS['arix_task_ran'] = false;

        $exit = (new ConsoleRuntime())->run($root);

        $this->assertSame(0, $exit);
        $this->assertTrue($GLOBALS['arix_task_ran']);
    }

    #[Test]
    public function it_returns_one_when_a_task_fails(): void
    {
        $root = $this->application([
            'broken' => 'static function (): void { throw new \RuntimeException("boom"); }',
        ]);

        $this->assertSame(1, (new ConsoleRuntime())->run($root));
    }

    #[Test]
    public function it_succeeds_when_the_application_has_no_schedule_file(): void
    {
        $root = $this->application([]);

        $this->assertSame(0, (new ConsoleRuntime())->run($root));
    }

    #[Test]
    public function it_reports_task_failures_on_standard_error(): void
    {
        $root = $this->application([
            'broken' => 'static function (): void { throw new \RuntimeException("boom"); }',
        ]);
        $autoload = StandaloneCore::autoload($root);
        $script = $root . DIRECTORY_SEPARATOR . 'cron.php';

        file_put_contents($script, "<?php\n\nrequire '{$autoload}';\n\nexit((new \\Arix\\Runtime\\ConsoleRuntime())->run('{$root}'));\n");

        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(1, proc_close($process), $output . $errors);
        $this->assertStringContainsString('failed: boom', $output);
        $this->assertStringContainsString('failed: boom', $errors);
    }

    /**
     * @param  array<string, string>  $events
     */
    private function application(array $events): string
    {
        $root = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-console-' . bin2hex(random_bytes(8));

        $directories = [
            'app' . DIRECTORY_SEPARATOR . 'config',
            'app' . DIRECTORY_SEPARATOR . 'views',
            'app' . DIRECTORY_SEPARATOR . 'lang',
            'storage' . DIRECTORY_SEPARATOR . 'app',
            'storage' . DIRECTORY_SEPARATOR . 'cache',
            'storage' . DIRECTORY_SEPARATOR . 'logs',
        ];

        foreach ($directories as $directory) {
            if (! mkdir($root . DIRECTORY_SEPARATOR . $directory, 0755, true)) {
                throw new RuntimeException('Cannot create the test application.');
            }
        }

        file_put_contents($root . DIRECTORY_SEPARATOR . '.arix.json', '{"version":"1.0.0","autoload":{"psr-4":{"App\\\\":"app/"}}}');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn ['debug' => false, 'base_path' => '', 'locales' => ['en'], 'default_locale' => 'en', 'name' => 'ArixPHP', 'max_body_bytes' => 1048576];\n");
        file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cache.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn ['version' => '1'];\n");
        file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'schedule.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn ['timezone' => 'UTC', 'grace' => 3600];\n");
        file_put_contents($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'routes.php', "<?php\n\ndeclare(strict_types=1);\n");

        if ($events !== []) {
            $body = '';

            foreach ($events as $name => $callback) {
                $body .= "    \$schedule->call('{$name}', {$callback})->everyMinute();\n";
            }

            file_put_contents(
                $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'schedules.php',
                "<?php\n\ndeclare(strict_types=1);\n\nuse Arix\\Scheduling\\Schedule;\n\nreturn static function (Schedule \$schedule): void {\n{$body}};\n"
            );
        }

        return $root;
    }
}
