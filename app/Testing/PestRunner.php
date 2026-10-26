<?php

declare(strict_types=1);

namespace App\Testing;

use Arix\Autoload\ClassLoader;
use Pest\Kernel;
use Pest\TestSuite;
use RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

use function count;

class PestRunner
{
    public static function run(string $application, string $distribution, bool $decorated): int
    {
        $root = realpath($application);

        if ($root === false || ! is_dir($root) || ! chdir($root)) {
            throw new RuntimeException('Cannot open application test directory.');
        }

        foreach (['core/autoload.php', 'tests/autoload.php', 'phpunit.xml'] as $required) {
            $file = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $required);

            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException("Application is missing {$required}.");
            }
        }

        // Arix must claim its global helpers before Composer includes Laravel's.
        require_once $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';
        $native = ClassLoader::current();

        if ($native === null || $native->configuration->root() !== $root) {
            throw new RuntimeException('Application bootstrap did not register its native autoloader.');
        }

        $configuration = $native->configuration;
        (new ClassLoader($configuration, development: true))->register();
        $autoload = $distribution . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (! is_file($autoload)) {
            $autoload = $distribution . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'autoload.php';
        }

        $_SERVER['COLLISION_PRINTER'] = 'DefaultPrinter';
        $loader = require $autoload;
        // Pest plugins belong to the CLI's dependency installation, not the app.
        $GLOBALS['_composer_bin_dir'] = dirname($autoload) . DIRECTORY_SEPARATOR . 'bin';
        $loader->unregister();
        $prefixes = array_unique(['App\\', 'Arix\\', 'Tests\\', ...array_keys($configuration->prefixes()), ...array_keys($configuration->devPrefixes())]);
        spl_autoload_register(static function (string $class) use ($loader, $prefixes): void {
            foreach ($prefixes as $owned) {
                if (str_starts_with($class, $owned)) {

                    return;
                }
            }

            $loader->loadClass($class);
        });

        $arguments = ['pest', '--configuration=' . $root . DIRECTORY_SEPARATOR . 'phpunit.xml', '--fail-on-empty-test-suite', '--colors=' . ($decorated ? 'always' : 'never')];
        $_SERVER['argv'] = $arguments;
        $_SERVER['argc'] = count($arguments);
        $_SERVER['COLLISION_PRINTER'] = 'DefaultPrinter';

        $suite = TestSuite::getInstance($root, 'tests');
        $kernel = Kernel::boot($suite, new ArgvInput($arguments), new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, $decorated));

        return $kernel->handle($arguments, $arguments);
    }
}
