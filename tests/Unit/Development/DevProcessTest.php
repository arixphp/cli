<?php

declare(strict_types=1);

namespace Tests\Unit\Development;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\NewApplicationFixture;

class DevProcessTest extends TestCase
{
    #[Test]
    public function it_serves_from_source_or_packaged_cli_and_cancels_without_orphans(): void
    {
        $runtime = getenv('ARIX_DEV_TEST_RUNTIME');

        if (! $runtime || PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX signal integration requires ARIX_DEV_TEST_RUNTIME; Windows cancellation needs its native lane.');
        }

        $fixture = new NewApplicationFixture();

        foreach (['index.php', 'app/bootstrap.php', 'core/autoload.php'] as $file) {
            $fixture->write($file, '<?php echo "Application response";');
        }

        $base = dirname(__DIR__, 3);
        $executable = getenv('ARIX_TEST_EXECUTABLE');
        $phar = getenv('ARIX_TEST_PHAR');
        $command = $executable ? [$executable] : [PHP_BINARY, $phar ?: $base . DIRECTORY_SEPARATOR . 'arix'];
        $command = [...$command, 'dev', '--path=' . $fixture->root, '--frankenphp=' . $runtime];
        $process = new Process($command, $base, ['XDEBUG_MODE' => 'off'], timeout: 25);

        try {
            $process->start();
            $deadline = microtime(true) + 20;

            do {
                $output = $process->getOutput();

                if (preg_match('/Development server ready: (http:\/\/[^\s]+)/', $output, $matches) === 1) {
                    break;
                }

                usleep(50000);
            } while ($process->isRunning() && microtime(true) < $deadline);

            $output = $process->getOutput();
            preg_match('/Development server ready: (http:\/\/[^\s]+)/', $output, $matches);
            $this->assertNotEmpty($matches[1] ?? '', $output . $process->getErrorOutput());
            $curl = curl_init($matches[1] . '/article?x=1');
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
            $this->assertSame('Application response', curl_exec($curl));
            $process->signal(SIGINT);
            $this->assertSame(130, $process->wait(), $process->getErrorOutput());
            $curl = curl_init($matches[1]);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 500]);
            $this->assertFalse(curl_exec($curl), 'Owned server must not remain after cancellation.');
        } finally {
            $process->stop(1);
            $fixture->cleanup();
        }
    }
}
