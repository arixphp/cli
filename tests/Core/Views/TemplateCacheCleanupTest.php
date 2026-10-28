<?php

declare(strict_types=1);

namespace Tests\Core\Views;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\TestApplication;

class TemplateCacheCleanupTest extends TestCase
{
    private string $application;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $root = getenv('ARIX_REFERENCE_ROOT');

        if ($root === false || $root === '') {
            $root = TestApplication::shared()->root;
        }

        $resolved = realpath($root);
        $this->assertNotFalse($resolved, 'ARIX_REFERENCE_ROOT must point to an existing application.');
        $this->application = $resolved;
        $this->assertFileExists($this->application . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php');
        $temporary = realpath(sys_get_temp_dir());
        $preferred = $temporary . DIRECTORY_SEPARATOR . 'opencode';
        $temporary = is_dir($preferred) ? $preferred : $temporary;
        $this->directory = $temporary . DIRECTORY_SEPARATOR . 'arix-template-cleanup-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'cache', 0700);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'views', 0700);
        TestApplication::prepareCore($this->directory);
    }

    protected function tearDown(): void
    {

        if (isset($this->directory)) {
            $this->remove($this->directory);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_loads_the_requested_application_core_without_composer(): void
    {
        $this->scenario('autoload');
    }

    #[Test]
    public function it_removes_an_old_compilation_after_a_view_changes(): void
    {
        $this->scenario('changed-view');
    }

    #[Test]
    public function it_validates_template_cache_policy_without_coercing_configuration(): void
    {
        $this->scenario('policy');
    }

    #[Test]
    public function it_collects_only_old_recognized_regular_files(): void
    {
        $old = $this->compiled('old', 100000);
        $boundary = $this->compiled('boundary', 113600);
        $recent = $this->compiled('recent', 199999);
        $future = $this->compiled('future', 200001);

        foreach (['.gitignore', 'compile-leftover', 'other.php', str_repeat('A', 64) . '.php'] as $name) {
            $this->fixture($name, 100000);
        }

        $directory = $this->cachePath(hash('sha256', 'directory') . '.php');
        mkdir($directory);
        touch($directory, 100000);
        $this->scenario('collect', ['now' => 200000]);
        $this->assertFileDoesNotExist($old);

        foreach ([$boundary, $recent, $future, $this->cachePath('.gitignore'), $this->cachePath('compile-leftover'), $this->cachePath('other.php'), $this->cachePath(str_repeat('A', 64) . '.php')] as $path) {
            $this->assertFileExists($path);
        }

        $this->assertDirectoryExists($directory);
    }

    #[Test]
    public function it_persists_scheduling_between_processes_and_recovers_invalid_state(): void
    {
        $this->scenario('collect', ['now' => 200000]);
        $old = $this->compiled('old', 100000);
        $this->scenario('collect', ['now' => 203599]);
        $this->assertFileExists($old);
        $this->scenario('collect', ['now' => 203600]);
        $this->assertFileDoesNotExist($old);

        foreach (['corrupt', '9999999999999999999999999999999999', '300000', '203600' . str_repeat(' ', 64) . 'corrupt'] as $state) {
            file_put_contents($this->cachePath('.templates.lock'), $state);
            $old = $this->compiled('recovery', 100000);
            $this->scenario('collect', ['now' => 203600]);
            $this->assertFileDoesNotExist($old);
            $this->assertSame('203600', trim(file_get_contents($this->cachePath('.templates.lock'))));
        }
    }

    #[Test]
    public function it_limits_each_batch_and_respects_disabled_cleanup(): void
    {

        for ($i = 0; $i < 101; $i++) {
            $this->compiled("old-{$i}", 100000);
        }

        $this->scenario('collect', ['now' => 200000, 'policy' => ['cleanup' => false]]);
        $this->assertCount(101, glob($this->cachePath('*.php')));
        $this->scenario('collect', ['now' => 200000]);
        $this->assertCount(1, glob($this->cachePath('*.php')));
        $this->scenario('collect', ['now' => 203600]);
        $this->assertCount(0, glob($this->cachePath('*.php')));
    }

    #[Test]
    public function it_preserves_linked_candidates_and_rejects_a_linked_lock(): void
    {
        $outside = $this->directory . DIRECTORY_SEPARATOR . 'outside';
        file_put_contents($outside, 'private');
        touch($outside, 100000);
        $linked = $this->cachePath(hash('sha256', 'linked') . '.php');

        if (! @symlink($outside, $linked)) {
            $this->markTestSkipped('Symlinks are unavailable on this platform.');
        }

        $this->scenario('collect', ['now' => 200000]);
        $this->assertTrue(is_link($linked));
        $this->assertSame('private', file_get_contents($outside));
        unlink($this->cachePath('.templates.lock'));
        symlink($outside, $this->cachePath('.templates.lock'));
        $this->scenario('rejected-lock');
        $this->assertSame('private', file_get_contents($outside));
    }

    #[Test]
    public function it_rejects_nonregular_lock_files_and_linked_directories(): void
    {
        mkdir($this->cachePath('.templates.lock'));
        $this->scenario('rejected-lock');
        rmdir($this->cachePath('.templates.lock'));
        $linked = $this->directory . DIRECTORY_SEPARATOR . 'cache-link';

        if (! @symlink($this->cachePath(), $linked)) {
            $this->markTestSkipped('Symlinks are unavailable on this platform.');
        }

        $this->scenario('rejected-directory', ['directory' => $linked]);
    }

    #[Test]
    public function it_distinguishes_optional_collection_errors_from_unsafe_render_lock_failure(): void
    {
        $old = $this->compiled('old', 100000);
        $this->scenario('maintenance-failure', ['failure' => 'delete']);
        $this->assertFileExists($old);
        file_put_contents($this->cachePath('.templates.lock'), '');
        $this->scenario('maintenance-failure', ['failure' => 'directory']);
        $this->assertSame('', file_get_contents($this->cachePath('.templates.lock')));
        $this->scenario('maintenance-failure', ['failure' => 'lock']);
    }

    #[Test]
    public function it_recompiles_an_expired_current_template_without_changing_its_cache_key(): void
    {
        $this->scenario('recompile');
    }

    #[Test]
    public function it_keeps_native_only_rendering_independent_of_compilation_storage(): void
    {
        $this->scenario('native');
        $this->assertFileDoesNotExist($this->cachePath('.templates.lock'));
    }

    #[Test]
    public function it_protects_nested_rendering_across_engines_and_releases_after_exceptions(): void
    {
        $this->scenario('nested');
        $this->scenario('collect', ['now' => time() + 100000]);
    }

    #[Test]
    public function it_does_not_delete_a_compilation_while_another_process_renders_it(): void
    {
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'held.arix.php', <<<'PHP'
            <?php
            touch(__FILE__, 100000);
            fwrite(STDERR, "READY\n");
            stream_set_timeout(STDIN, 10);
            if (trim((string) fgets(STDIN)) !== 'release') { throw new RuntimeException('Render handshake timed out.'); }
            ?>held
            PHP);
        $input = new InputStream();
        $process = $this->worker('render-held');
        $process->setInput($input);
        $process->start();

        try {
            $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "READY\n")));
            $compiled = glob($this->cachePath('*.php'));
            $this->assertCount(1, $compiled);
            $this->scenario('collect', ['now' => time() + 100000]);
            $this->assertFileExists($compiled[0]);
            $input->write("release\n");
            $input->close();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('ok', trim($process->getOutput()));
            $this->scenario('collect', ['now' => time() + 100000]);
            $this->assertFileDoesNotExist($compiled[0]);
        } finally {
            $input->close();
            $process->stop(0);
        }
    }

    #[Test]
    public function it_runs_only_one_due_collection_when_processes_contend(): void
    {
        $this->compiled('first', 100000);
        $this->compiled('second', 100000);
        $input = new InputStream();
        $first = $this->worker('collector-held');
        $first->setInput($input);
        $second = $this->worker('collector-follow');
        $first->start();

        try {
            $this->assertTrue($first->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "READY\n")));
            $second->start();
            $this->assertTrue($second->waitUntil(static fn (string $type, string $output): bool => str_contains($output, "ATTEMPT\n")));
            $input->write("release\n");
            $input->close();
            $first->wait();
            $second->wait();
            $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
            $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
            $this->assertCount(1, glob($this->cachePath('*.php')));
            $this->assertSame('200000', trim(file_get_contents($this->cachePath('.templates.lock'))));
        } finally {
            $input->close();
            $first->stop(0);
            $second->stop(0);
        }
    }

    #[Test]
    public function it_forwards_custom_policy_through_view_services(): void
    {
        $this->scenario('services', ['policy' => ['interval' => 1, 'max_age' => 10, 'max_deletions' => 1], 'remaining' => 1]);
    }

    #[Test]
    public function it_preserves_defaults_for_existing_view_service_callers(): void
    {
        $this->scenario('services', ['remaining' => 2]);
    }

    #[Test]
    public function it_disables_collection_through_view_services(): void
    {
        $this->scenario('services', ['policy' => ['cleanup' => false, 'max_age' => 10], 'remaining' => 2]);
    }

    #[Test]
    public function it_rejects_explicitly_invalid_view_service_policy(): void
    {
        $this->scenario('services-invalid');
    }

    #[Test]
    public function it_forwards_custom_policy_from_application_configuration(): void
    {
        $this->scenario('application', ['policy' => ['interval' => 1, 'max_age' => 10, 'max_deletions' => 1], 'remaining' => 1]);
    }

    #[Test]
    public function it_honors_disabled_collection_in_application_configuration(): void
    {
        $this->scenario('application', ['policy' => ['cleanup' => false, 'max_age' => 10], 'remaining' => 2]);
    }

    #[Test]
    public function it_rejects_a_configured_application_cache_path(): void
    {
        $this->scenario('reserved-cache-path');
    }

    #[Test]
    public function it_rejects_explicit_null_in_application_configuration(): void
    {
        $this->scenario('application', ['policy' => null, 'invalid' => true]);
    }

    #[Test]
    public function it_creates_the_first_lock_without_emitting_filesystem_warnings(): void
    {
        $this->scenario('first-render-warnings');
    }

    private function compiled(string $label, int $mtime): string
    {

        return $this->fixture(hash('sha256', $label) . '.php', $mtime);
    }

    private function fixture(string $name, int $mtime): string
    {
        $path = $this->cachePath($name);
        file_put_contents($path, '<?php echo "fixture";');
        touch($path, $mtime);

        return $path;
    }

    private function cachePath(string $name = ''): string
    {

        return $this->directory . DIRECTORY_SEPARATOR . 'cache' . ($name === '' ? '' : DIRECTORY_SEPARATOR . $name);
    }

    private function scenario(string $name, array $options = []): void
    {
        $process = $this->worker($name, $options);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame('ok', trim($process->getOutput()));
    }

    private function worker(string $name, array $options = []): Process
    {
        $worker = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'TemplateCacheCleanup' . DIRECTORY_SEPARATOR . 'worker.php';
        $process = new Process([PHP_BINARY, $worker, $name, $this->application, $this->directory, json_encode($options, JSON_THROW_ON_ERROR)]);
        $process->setTimeout(20);

        return $process;
    }

    private function remove(string $path): void
    {

        if (is_link($path) || ! is_dir($path)) {
            unlink($path);

            return;
        }

        foreach (new \FilesystemIterator($path) as $entry) {
            $this->remove($entry->getPathname());
        }

        rmdir($path);
    }
}
