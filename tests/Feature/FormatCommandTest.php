<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\Format;
use App\Formatting\FormatProcess;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Tests\Support\ApplicationTestingFixture;
use Tests\TestCase;

class FormatCommandTest extends TestCase
{
    private ApplicationTestingFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new ApplicationTestingFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_formats_app_and_tests_directories(): void
    {
        $this->fixture->write('app/ExampleController.php', '<?php\nclass ExampleController{\npublic function index(){return 1;}\n}\n');
        $this->fixture->write('tests/ExampleTest.php', '<?php\ntest("example", function () { expect(1)->toBe(1); });');

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_excludes_app_views_directory(): void
    {
        $this->fixture->write('app/views/Layout.arix.php', '<?php // view');
        $this->fixture->write('app/Controller.php', '<?php\nclass Controller{}\n');

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_uses_user_config_file(): void
    {
        $this->fixture->write('.arix-format.json', '{"rules":{"no_unused_imports":true}}');
        $this->fixture->write('app/Controller.php', '<?php\nclass Controller{}\n');

        $this->artisan('format', [
            '--path' => $this->fixture->root,
            '--config' => $this->fixture->path('.arix-format.json'),
        ])->assertExitCode(0);
    }

    #[Test]
    public function it_rejects_config_with_directories(): void
    {
        $this->fixture->write('.arix-format.json', '{"paths":{"include":["src"]}}');

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->expectsOutputToContain('Unsupported key')
            ->assertExitCode(2);
    }

    #[Test]
    public function it_rejects_missing_application(): void
    {
        $this->artisan('format', ['--path' => '/nonexistent/path'])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(2);
    }

    #[Test]
    public function it_reports_formatting_violations_without_modifying_code(): void
    {
        $this->fixture->write('app/Controller.php', "<?php\ndeclare(strict_types=1);\nclass Controller{public function index(){return 1;}}\n");
        $this->fixture->write('tests/ExampleTest.php', "<?php\ndeclare(strict_types=1);\ntest('example',function(){expect(1)->toBe(1);});\n");
        $contents = $this->applicationPhpContents();

        $this->artisan('format', ['--path' => $this->fixture->root, '--test' => true])
            ->assertExitCode(1);

        $this->assertSame($contents, $this->applicationPhpContents());
    }

    #[Test]
    public function it_passes_test_mode_for_formatted_code(): void
    {
        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);

        $contents = $this->applicationPhpContents();

        $this->artisan('format', ['--path' => $this->fixture->root, '--test' => true])
            ->assertExitCode(0);

        $this->assertSame($contents, $this->applicationPhpContents());
    }

    #[Test]
    public function it_applies_custom_rules_in_test_mode(): void
    {
        $this->fixture->write('.arix-format.json', '{"rules":{"no_unused_imports":false}}');
        $this->fixture->write('app/Controller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

class Controller {}
PHP);

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);

        $contents = file_get_contents($this->fixture->path('app/Controller.php'));
        $this->assertStringContainsString('use RuntimeException;', $contents);
        $this->fixture->write('rules.json', '{"rules":{"no_unused_imports":true}}');

        $this->artisan('format', [
            '--path' => $this->fixture->root,
            '--config' => $this->fixture->path('rules.json'),
            '--test' => true,
        ])->assertExitCode(1);

        $this->assertSame($contents, file_get_contents($this->fixture->path('app/Controller.php')));
    }

    #[Test]
    public function it_excludes_app_views_in_test_mode(): void
    {
        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);

        $view = "<?php\ndeclare(strict_types=1);\necho( 'view' );\n";
        $this->fixture->write('app/views/Layout.arix.php', $view);

        $this->artisan('format', ['--path' => $this->fixture->root, '--test' => true])
            ->assertExitCode(0);

        $this->assertSame($view, file_get_contents($this->fixture->path('app/views/Layout.arix.php')));
    }

    #[Test]
    public function it_rejects_missing_application_in_test_mode(): void
    {
        $this->artisan('format', ['--path' => '/nonexistent/path', '--test' => true])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(2);
    }

    #[Test]
    public function it_only_checks_application_owned_php_directories(): void
    {
        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);

        $code = "<?php\ndeclare(strict_types=1);\necho( 'outside' );\n";
        $this->fixture->write('core/Outside.php', $code);
        $this->fixture->write('outside.php', $code);

        $this->artisan('format', ['--path' => $this->fixture->root, '--test' => true])
            ->assertExitCode(0);

        $this->assertSame($code, file_get_contents($this->fixture->path('core/Outside.php')));
        $this->assertSame($code, file_get_contents($this->fixture->path('outside.php')));
    }

    private function applicationPhpContents(): array
    {
        $files = Finder::create()->files()->name('*.php')->in([
            $this->fixture->path('app'),
            $this->fixture->path('tests'),
        ])->sortByName();
        $contents = [];

        foreach ($files as $file) {
            $contents[$file->getPathname()] = $file->getContents();
        }

        return $contents;
    }

    #[Test]
    public function it_fixes_real_php_code_and_then_passes_check_mode(): void
    {
        $code = "<?php\ndeclare(strict_types=1);\nclass Controller{public function index(){return 1;}}\n";
        $this->fixture->write('app/Controller.php', $code);

        $this->artisan('format', ['--path' => $this->fixture->root, '--test' => true])
            ->assertExitCode(1);

        $this->assertSame($code, file_get_contents($this->fixture->path('app/Controller.php')));

        $this->artisan('format', ['--path' => $this->fixture->root])
            ->assertExitCode(0);

        $this->assertNotSame($code, file_get_contents($this->fixture->path('app/Controller.php')));

        $this->artisan('format', ['--path' => $this->fixture->root, '--test' => true])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_preserves_format_worker_output_channels(): void
    {
        $this->app->instance(FormatProcess::class, new class() extends FormatProcess
        {
            public function run(string $application, string $entry, string $config, bool $test, callable $output, bool $selfExecutable = false): int
            {
                $output(Process::OUT, 'format stdout');
                $output(Process::ERR, 'format stderr');

                return 7;
            }
        });
        $command = $this->app->make(Format::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $status = $tester->execute(['--path' => $this->fixture->root], ['capture_stderr_separately' => true]);
        $this->assertSame(7, $status);
        $this->assertSame('format stdout', $tester->getDisplay());
        $this->assertSame('format stderr', $tester->getErrorOutput());
    }
}
