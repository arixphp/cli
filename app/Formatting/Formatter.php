<?php

declare(strict_types=1);

namespace App\Formatting;

use App\Testing\ApplicationTarget;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ConsoleOutputInterface;
use Symfony\Component\Process\Process;

use function in_array;
use function is_array;
use function is_file;
use function is_link;
use function is_readable;

class Formatter
{
    private const array ALLOWED_CONFIG_KEYS = ['version', 'rules'];

    public function format(string|null $path, string|null $config, string $cwd, OutputInterface $output): int
    {
        $application = ApplicationTarget::resolve($path, $cwd);

        $rulesFile = $config ?? ($application . DIRECTORY_SEPARATOR . '.arix-format.json');
        $rules = [];

        if (is_file($rulesFile)) {
            if (is_link($rulesFile) || ! is_readable($rulesFile)) {
                throw new RuntimeException('Format rules file is unavailable.');
            }

            $rules = $this->loadRules($rulesFile);
        }

        $tempConfig = $this->buildTempConfig($application, $rules);

        try {
            $pint = base_path('vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'pint');

            if (! is_file($pint) || ! is_readable($pint)) {
                throw new RuntimeException('Pint is not available in the CLI installation.');
            }

            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

            $process = new Process([PHP_BINARY, $pint, '--config=' . $tempConfig, '--no-ansi'], $application);
            $process->run(static function (string $type, string $buffer) use ($stderr): void {
                $stderr->write($buffer, false);
            });

            return $process->getExitCode();
        } finally {
            if ($tempConfig !== null && is_file($tempConfig)) {
                unlink($tempConfig);
            }
        }
    }

    private function loadRules(string $file): array
    {
        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException('Cannot read format rules file.');
        }

        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('Format rules file must contain a JSON object.');
        }

        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::ALLOWED_CONFIG_KEYS, true)) {
                throw new RuntimeException("Unsupported key in format rules file: {$key}");
            }
        }

        return $data;
    }

    private function buildTempConfig(string $application, array $userConfig): string
    {
        $config = $userConfig;
        $config['paths'] = [
            'include' => ['app', 'tests'],
            'exclude' => ['app/views'],
        ];

        $temp = tempnam($application, 'arix-format-');

        if ($temp === false) {
            throw new RuntimeException('Cannot create temporary format configuration.');
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        if (file_put_contents($temp, $json) === false) {
            unlink($temp);
            throw new RuntimeException('Cannot write temporary format configuration.');
        }

        return $temp;
    }
}
