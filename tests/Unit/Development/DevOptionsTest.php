<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use App\Development\DevOptions;
use App\Development\PortCandidates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\NewApplicationFixture;

class DevOptionsTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();

        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php'] as $file) {
            $this->fixture->write($file, '<?php throw new RuntimeException("Must not run");');
        }
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_defaults_to_invocation_directory_without_requiring_tests(): void
    {
        $options = DevOptions::fromInput([], $this->fixture->root);

        $this->assertSame($this->fixture->root, $options->root);
        $this->assertSame('http://127.0.0.1:8181', $options->address(8181));
        $this->assertTrue($options->reload);
        $this->assertSame(range(8181, 8200), PortCandidates::for(8181, false));
        $this->assertSame([65535], PortCandidates::for(65535, false));
        $this->assertSame([8181], PortCandidates::for(8181, true));
    }

    #[Test]
    public function it_resolves_relative_paths_and_ipv6(): void
    {
        $this->fixture->write('config/Caddyfile', 'custom');

        $options = DevOptions::fromInput(['path' => '.', 'host' => '::1', 'port' => '9000', 'caddyfile' => 'config/Caddyfile'], $this->fixture->root);

        $this->assertSame('http://[::1]:9000', $options->address(9000));
        $this->assertSame($this->fixture->path('config/Caddyfile'), $options->caddyfile);
    }

    #[Test]
    #[DataProvider('invalidOptions')]
    public function it_rejects_invalid_options_before_starting(array $input): void
    {
        $this->expectException(RuntimeException::class);

        DevOptions::fromInput($input, $this->fixture->root);
    }

    public static function invalidOptions(): array
    {
        return [[['host' => '0.0.0.0']], [['host' => 'https://localhost']], [['host' => "localhost\n}"]], [['port' => '0']], [['port' => '65536']], [['port' => '12.5']], [['port' => '-1']], [['caddyfile' => 'missing']], [['path' => 'missing']]];
    }

    #[Test]
    public function it_allows_network_binding_only_without_reload(): void
    {
        $options = DevOptions::fromInput(['host' => '0.0.0.0', 'no-reload' => true], $this->fixture->root);

        $this->assertFalse($options->reload);
    }

    #[Test]
    public function it_separates_wildcard_binding_from_the_site_hostname(): void
    {
        $v4 = DevOptions::fromInput(['host' => '0.0.0.0', 'no-reload' => true], $this->fixture->root);

        $this->assertSame('http://127.0.0.1:9000', $v4->address(9000));
        $this->assertSame('http://:9000', $v4->site(9000));

        $v6 = DevOptions::fromInput(['host' => '::', 'no-reload' => true], $this->fixture->root);

        $this->assertSame('http://[::1]:9000', $v6->address(9000));
        $this->assertSame('http://:9000', $v6->site(9000));

        $loopback = DevOptions::fromInput([], $this->fixture->root);

        $this->assertSame('http://127.0.0.1:8181', $loopback->site(8181));
    }

    #[Test]
    #[DataProvider('linkedRobotsTargets')]
    public function it_rejects_a_linked_robots_file(string $target): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation needs Windows privileges.');
        }

        if ($target !== 'missing-target') {
            $this->fixture->write($target, 'SECRET');
        }

        symlink($this->fixture->path($target), $this->fixture->path('robots.txt'));

        $this->expectException(RuntimeException::class);
        DevOptions::fromInput([], $this->fixture->root);
    }

    public static function linkedRobotsTargets(): array
    {
        return [['core/target.txt'], ['missing-target']];
    }

    #[Test]
    public function it_rejects_roots_that_caddy_would_rewrite_as_placeholders(): void
    {
        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php'] as $file) {
            $this->fixture->write('braced{$ARIX_DEV_HOST}/' . $file, '<?php');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('must not contain');

        DevOptions::fromInput(['path' => 'braced{$ARIX_DEV_HOST}'], $this->fixture->root);
    }

    #[Test]
    public function it_rejects_links_in_public_directories(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlink creation needs Windows privileges.');
        }

        $this->fixture->write('styles/app.css', 'body{}');

        symlink($this->fixture->path('missing'), $this->fixture->path('styles/linked.css'));

        $this->expectException(RuntimeException::class);

        DevOptions::fromInput([], $this->fixture->root);
    }
}
