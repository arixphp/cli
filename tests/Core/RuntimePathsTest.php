<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Support\RuntimePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\NativeLink;

class RuntimePathsTest extends TestCase
{
    private string $directory;

    #[Test]
    public function it_does_not_expose_strict_root_factories(): void
    {
        $this->assertFalse(method_exists(RuntimePaths::class, 'fromStrictRoot'));
        $this->assertFalse(method_exists(RuntimePaths::class, 'fromOptionalStrictRoot'));
    }

    #[Test]
    public function it_resolves_trusted_internal_directory_links(): void
    {
        $release = $this->directory . DIRECTORY_SEPARATOR . 'release';
        $alias = $release . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Alias';
        symlink($release . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers', $alias);
        $paths = RuntimePaths::fromApplicationRoot($release);

        $this->assertSame($alias . DIRECTORY_SEPARATOR . 'Home.php', $paths->resolve(['app', 'Alias', 'Home.php']));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'arix-boundary-' . bin2hex(random_bytes(8));
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers', 0755, true);
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Home.php', '<?php declare(strict_types=1);');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function it_resolves_a_trusted_root_alias_and_its_canonical_target(): void
    {
        $release = $this->directory . DIRECTORY_SEPARATOR . 'release';
        $alias = $this->directory . DIRECTORY_SEPARATOR . 'current';
        symlink($release, $alias);
        $paths = RuntimePaths::fromApplicationRoot($alias);

        $this->assertSame($release, $paths->root);
        $this->assertSame($release . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Home.php', $paths->resolve(['app', 'Controllers', 'Home.php']));
        $this->assertSame($release, RuntimePaths::fromApplicationRoot($alias . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . '..')->root);
    }

    #[Test]
    #[DataProvider('internalLinks')]
    public function it_resolves_internal_links_to_existing_targets(string $link, string $target, array $segments, bool $directory): void
    {
        $release = $this->directory . DIRECTORY_SEPARATOR . 'release';
        $link = $release . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $link);
        $target = $this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $target);
        NativeLink::create($target, $link, $directory);
        $paths = RuntimePaths::fromApplicationRoot($release);
        if (! file_exists($target)) {
            $this->assertSame(file_exists($link) ? $link : null, $paths->resolve($segments));

            return;
        }

        $this->assertSame($release . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments), $paths->resolve($segments));
    }

    public static function internalLinks(): array
    {

        return [
            'same-root file' => ['app/Controllers/Linked.php', 'release/app/Controllers/Home.php', ['app', 'Controllers', 'Linked.php'], false],
            'same-root directory' => ['app/Alias', 'release/app/Controllers', ['app', 'Alias', 'Home.php'], true],
            'outside root directory' => ['app/Outside', '.', ['app', 'Outside', 'release', 'app', 'Controllers', 'Home.php'], true],
            'broken optional file' => ['app/services.php', 'missing.php', ['app', 'services.php'], false],
            'linked app directory' => ['linkedApp', 'release/app', ['linkedApp', 'Controllers', 'Home.php'], true],
        ];
    }

    #[Test]
    #[DataProvider('invalidSegments')]
    public function it_rejects_unsafe_path_segments(array $segments): void
    {
        $paths = RuntimePaths::fromApplicationRoot($this->directory . DIRECTORY_SEPARATOR . 'release');
        $this->expectException(RuntimeException::class);

        $paths->resolve($segments);
    }

    public static function invalidSegments(): array
    {

        return array_map(static fn (array $segments): array => [$segments], [
            [], [''], ['.'], ['..'], ['app', '..', 'release-other'], ['app/Controllers'], ['app\\Controllers'],
            ['/absolute'], ['C:'], ['phar://payload'], ["a\0b"], ["a\nb"], ['missing', '..'], [12],
        ]);
    }

    #[Test]
    public function it_returns_null_for_missing_descendants(): void
    {
        $paths = RuntimePaths::fromApplicationRoot($this->directory . DIRECTORY_SEPARATOR . 'release');

        $this->assertNull($paths->resolve(['app', 'services.php']));
    }

    #[Test]
    public function it_resolves_a_native_windows_descendant_junction(): void
    {

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Requires native Windows junction semantics; not verified on this platform.');
        }

        $link = $this->directory . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'redirect';
        $target = $this->directory . DIRECTORY_SEPARATOR . 'outside';
        mkdir($target);
        $this->createJunction($link, $target);

        try {
            $paths = RuntimePaths::fromApplicationRoot($this->directory . DIRECTORY_SEPARATOR . 'release');
            $this->assertSame($link, $paths->resolve(['redirect']));
        } finally {
            $this->removeJunction($link);
        }
    }

    private function createJunction(string $link, string $target): void
    {
        $process = new Process(['cmd.exe', '/c', 'mklink', '/J', $link, $target]);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
        clearstatcache(true, $link);
        $this->assertTrue(is_dir($link));
    }

    private function removeJunction(string $link): void
    {
        clearstatcache(true, $link);

        if (is_dir($link) && ! is_link($link)) {
            @rmdir($link);
        }
    }

    #[Test]
    #[DataProvider('invalidRoots')]
    public function it_rejects_invalid_application_roots(string $root): void
    {
        $this->expectException(RuntimeException::class);

        RuntimePaths::fromApplicationRoot($root);
    }

    public static function invalidRoots(): array
    {

        return [[''], ["a\0b"], ['phar://payload'], ['https://example.test/site']];
    }

    #[Test]
    public function it_resolves_trusted_components_replaced_with_links(): void
    {
        $release = $this->directory . DIRECTORY_SEPARATOR . 'release';
        $paths = RuntimePaths::fromApplicationRoot($release);
        $this->assertNotNull($paths->resolve(['app', 'Controllers', 'Home.php']));
        rename($release . DIRECTORY_SEPARATOR . 'app', $release . DIRECTORY_SEPARATOR . 'saved');
        symlink($release . DIRECTORY_SEPARATOR . 'saved', $release . DIRECTORY_SEPARATOR . 'app');
        $this->assertNotNull($paths->resolve(['app', 'Controllers', 'Home.php']));
    }

    #[Test]
    public function it_does_not_re_resolve_a_changed_deployment_alias(): void
    {
        $alias = $this->directory . DIRECTORY_SEPARATOR . 'current';
        symlink($this->directory . DIRECTORY_SEPARATOR . 'release', $alias);
        $paths = RuntimePaths::fromApplicationRoot($alias);
        unlink($alias);
        symlink($this->directory, $alias);

        $this->assertSame($this->directory . DIRECTORY_SEPARATOR . 'release', $paths->root);
        $this->assertNotNull($paths->resolve(['app', 'Controllers', 'Home.php']));
    }

    #[Test]
    public function it_returns_null_when_a_path_cannot_be_accessed(): void
    {
        $app = $this->directory . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR . 'app';
        $paths = RuntimePaths::fromApplicationRoot($this->directory . DIRECTORY_SEPARATOR . 'release');
        chmod($app, 0000);

        try {

            if (is_readable($app)) {
                $this->markTestSkipped('This user/filesystem bypasses directory read permissions.');
            }

            $this->assertNull($paths->resolve(['app', 'Controllers', 'Home.php']));
        } finally {
            chmod($app, 0755);
        }
    }
}
