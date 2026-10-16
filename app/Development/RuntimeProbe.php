<?php

declare(strict_types=1);

namespace App\Development;

use RuntimeException;
use Symfony\Component\Process\Process;

class RuntimeProbe
{
    public function verify(string $executable, bool $reload = true): void
    {
        if (! is_file($executable) || (PHP_OS_FAMILY !== 'Windows' && ! is_executable($executable))) {
            throw new RuntimeException('FrankenPHP is missing or is not executable.');
        }

        $session = new SessionFiles();

        try {
            $probe = $session->directory . DIRECTORY_SEPARATOR . 'probe.php';

            file_put_contents($probe, '<?php declare(strict_types=1); echo PHP_VERSION_ID >= 80401 && extension_loaded("mbstring") ? "ARIX_RUNTIME_OK" : "ARIX_RUNTIME_UNSUPPORTED";');

            $process = new Process([$executable, 'php-cli', $probe], $session->directory, ['PHPRC' => false, 'PHP_INI_SCAN_DIR' => false], timeout: 20);
            $process->run();

            if (! $process->isSuccessful() || trim($process->getOutput()) !== 'ARIX_RUNTIME_OK') {
                throw new RuntimeException('FrankenPHP must supply PHP >= 8.4.1 and ext-mbstring. ' . $process->getErrorOutput());
            }

            $modules = new Process([$executable, 'list-modules'], $session->directory, timeout: 20);
            $modules->run();

            if (! $modules->isSuccessful() || ! str_contains($modules->getOutput(), 'http.handlers.php') || ($reload && ! str_contains($modules->getOutput(), 'http.handlers.mercure'))) {
                throw new RuntimeException('FrankenPHP requires the PHP module and Mercure for hot reload.');
            }
        } finally {
            $session->cleanup();
        }
    }
}
