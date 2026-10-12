<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CorePackager;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use LaravelZero\Framework\Commands\Command;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\CoreUpdateFixture;
use Tests\TestCase;

class CorePackageCommandTest extends TestCase
{
    private CoreUpdateFixture $fixture;

    private CorePackager $packager;

    private string $bundle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new CoreUpdateFixture();
        $source = $this->fixture->core('cli/core');
        $this->bundle = $this->fixture->path('cli/resources/core.bundle');
        $this->packager = new CorePackager($source, $this->bundle);
        $this->app->instance(CorePackager::class, $this->packager);
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_builds_using_the_packager_service(): void
    {
        $this->artisan('core:package')
            ->expectsOutputToContain('Core bundle verified')
            ->assertExitCode(0);

        $this->assertSame("<?php\ndeclare(strict_types=1);\n// kernel\n", CoreDistribution::load($this->bundle)->contents('Http/Kernel.php'));
    }

    #[Test]
    public function it_checks_without_writing(): void
    {
        $this->packager->build();
        touch($this->bundle, 10000);
        $bytes = file_get_contents($this->bundle);

        $this->artisan('core:package', ['--check' => true])
            ->expectsOutputToContain('Core bundle verified')
            ->assertExitCode(0);

        clearstatcache(true, $this->bundle);
        $this->assertSame(10000, filemtime($this->bundle));
        $this->assertSame($bytes, file_get_contents($this->bundle));
    }

    #[Test]
    public function it_reports_a_missing_bundle_without_creating_resources(): void
    {
        $this->artisan('core:package', ['--check' => true])
            ->expectsOutputToContain('Cannot read')
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->fixture->path('cli/resources'));
    }

    #[Test]
    public function it_reports_stale_sources_without_regenerating_them_during_check(): void
    {
        $this->packager->build();
        $before = file_get_contents($this->bundle);
        $this->fixture->write('cli/core/Http/Kernel.php', '<?php // changed');

        $this->artisan('core:package', ['--check' => true])
            ->expectsOutputToContain('stale')
            ->assertExitCode(1);

        $this->assertSame($before, file_get_contents($this->bundle));
    }

    #[Test]
    public function it_does_not_package_when_help_is_requested(): void
    {
        $this->artisan('core:package', ['--help' => true])->assertExitCode(0);
        $this->artisan('app:build', ['--help' => true])->assertExitCode(0);

        $this->assertDirectoryDoesNotExist($this->fixture->path('cli/resources'));
    }

    #[Test]
    public function it_prepares_the_bundle_before_the_build_command_body_runs(): void
    {
        $this->packager->build();
        $this->fixture->write('cli/core/Http/Kernel.php', '<?php // current release');
        $marker = $this->fixture->path('compiler-observed.txt');
        $kernel = $this->app->make(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();

        // Replace only the expensive external compiler, not the packaging service.
        $kernel->registerCommand(new class($this->bundle, $marker) extends Command
        {
            protected $signature = 'app:build';

            public function __construct(private readonly string $bundle, private readonly string $marker)
            {
                parent::__construct();
            }

            public function handle(): int
            {
                file_put_contents($this->marker, CoreDistribution::load($this->bundle)->contents('Http/Kernel.php'));

                return self::SUCCESS;
            }
        });

        $this->artisan('app:build')->assertExitCode(0);

        $this->assertSame('<?php // current release', file_get_contents($marker));
    }

    #[Test]
    public function it_blocks_build_preparation_when_imported_integrity_fails(): void
    {
        $this->fixture->write('cli/core/FastRoute/functions.php', '<?php // unmanaged upstream');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Imported');
        $this->app['events']->dispatch(new CommandStarting('app:build', new ArrayInput(['command' => 'app:build']), new BufferedOutput()));
    }

    #[Test]
    public function it_does_not_prepare_a_bundle_for_other_commands_or_help_events(): void
    {
        foreach ([['core:update', []], ['app:build', ['--help' => true]]] as [$command, $options]) {
            $this->app['events']->dispatch(new CommandStarting($command, new ArrayInput(['command' => $command, ...$options]), new BufferedOutput()));
        }

        $this->assertDirectoryDoesNotExist($this->fixture->path('cli/resources'));
    }
}
