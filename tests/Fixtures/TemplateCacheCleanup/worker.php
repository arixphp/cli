<?php

declare(strict_types=1);

use Arix\Application;
use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\Views\TemplateCacheMaintenance;
use Arix\Views\TemplateCachePolicy;
use Arix\Views\TemplateEngine;
use Arix\Views\ViewServices;

require $argv[2] . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'autoload.php';

$scenario = $argv[1];
$root = $argv[3];
$options = json_decode($argv[4], true, flags: JSON_THROW_ON_ERROR);
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
$storageCache = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';
$views = $root . DIRECTORY_SEPARATOR . 'views';

function check(bool $condition, string $message): void
{

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

switch ($scenario) {
    case 'first-render-warnings':
        file_put_contents($views . DIRECTORY_SEPARATOR . 'page.arix.php', 'First');
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $output = (new TemplateEngine($views, $cache))->render('page');
        } finally {
            restore_error_handler();
        }

        check($output === 'First' && $warnings === [], 'First render emitted filesystem warnings: ' . implode('; ', $warnings));
        break;

    case 'services':
    case 'services-invalid':
        mkdir($root . DIRECTORY_SEPARATOR . 'lang');
        mkdir($storageCache, 0700, true);
        file_put_contents($views . DIRECTORY_SEPARATOR . 'page.arix.php', 'Configured');
        $configuration = [
            'app' => ['locales' => ['en'], 'default_locale' => 'en'],
            'cache' => ['version' => 'custom-version'],
            'views' => $views,
            'lang' => $root . DIRECTORY_SEPARATOR . 'lang',
        ];

        if ($scenario === 'services-invalid') {

            foreach ([null, false, ['max_age' => '10'], ['cleanup' => null]] as $invalid) {
                $configuration['cache']['templates'] = $invalid;

                try {
                    ViewServices::register(new Container(), $root, $configuration);
                } catch (InvalidArgumentException) {
                    continue;
                }

                throw new RuntimeException('View services accepted an invalid policy.');
            }

            break;
        }

        if (array_key_exists('policy', $options)) {
            $configuration['cache']['templates'] = $options['policy'];
        }

        $old = seedOldCompilations($storageCache, ($options['policy']['cleanup'] ?? true) ? 100 : 90000);
        $container = new Container();
        ViewServices::register($container, $root, $configuration);
        check($container->get(TemplateEngine::class)->render('page') === 'Configured', 'Configured view failed.');
        check(remaining($old) === $options['remaining'], 'View services did not apply the requested collection policy.');
        break;

    case 'application':
        foreach (['config', 'Controllers', 'views', 'lang'] as $directory) {
            mkdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $directory, 0700, true);
        }

        mkdir($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app', 0700, true);
        mkdir($storageCache, 0700, true);
        $app = $root . DIRECTORY_SEPARATOR . 'app';
        file_put_contents($app . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', '<?php declare(strict_types=1); return ["locales" => ["en"], "default_locale" => "en", "debug" => true];');
        $cacheConfig = ['version' => 'application-version', 'templates' => $options['policy']];
        file_put_contents($app . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cache.php', '<?php declare(strict_types=1); return ' . var_export($cacheConfig, true) . ';');
        file_put_contents($app . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'page.arix.php', 'Configured');
        file_put_contents($app . DIRECTORY_SEPARATOR . 'routes.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            Arix\Facades\Route::get('/', [App\Controllers\CacheController::class, 'index']);
            PHP);
        file_put_contents($app . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'CacheController.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace App\Controllers;
            class CacheController
            {
                public function index(): \Arix\Views\Contracts\View
                {
                    return new \Arix\Views\View('page');
                }
            }
            PHP);
        $old = seedOldCompilations($storageCache, ($options['policy']['cleanup'] ?? true) ? 100 : 90000);
        $kernel = Application::create($root)->kernel();
        $response = $kernel->handle(Request::create('/'));

        if ($options['invalid'] ?? false) {
            check($response->getStatusCode() === 500 && str_contains($response->getContent(), 'cache.templates'), 'Application ignored explicit null policy.');
            break;
        }

        check($response->getStatusCode() === 200 && $response->getContent() === 'Configured', 'Application render failed: ' . $response->getContent());
        check(remaining($old) === $options['remaining'], 'Application did not forward the requested collection policy.');
        check(glob($storageCache . DIRECTORY_SEPARATOR . '*.php') !== [], 'Application did not compile templates into the framework-owned cache.');
        check(glob($cache . DIRECTORY_SEPARATOR . '*.php') === [], 'Application compiled templates outside the framework-owned cache.');

        if ($options['remaining'] === 1) {
            file_put_contents($storageCache . DIRECTORY_SEPARATOR . '.templates.lock', (string) (time() - 2));
            $response = $kernel->handle(Request::create('/'));
            check($response->getStatusCode() === 200 && remaining($old) === 0, 'Application lost custom interval or request isolation.');
        }

        break;
    case 'reserved-cache-path':
        foreach (['config', 'Controllers', 'views', 'lang'] as $directory) {
            mkdir($root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . $directory, 0700, true);
        }

        mkdir($root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'app', 0700, true);
        mkdir($storageCache, 0700, true);
        $app = $root . DIRECTORY_SEPARATOR . 'app';
        file_put_contents($app . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php', '<?php declare(strict_types=1); return ["locales" => ["en"], "default_locale" => "en", "debug" => true];');
        file_put_contents($app . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cache.php', '<?php declare(strict_types=1); return ["path" => "/var/www/html", "version" => "1"];');
        file_put_contents($app . DIRECTORY_SEPARATOR . 'routes.php', '<?php declare(strict_types=1);');
        file_put_contents($app . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . 'page.arix.php', 'Configured');

        try {
            Application::create($root)->kernel();
            throw new RuntimeException('Application accepted a reserved cache path.');
        } catch (InvalidArgumentException $exception) {
            check(str_contains($exception->getMessage(), "reserved configuration key 'cache.path'"), 'Unexpected failure: ' . $exception->getMessage());
        }

        break;
    case 'render-held':
        check((new TemplateEngine($views, $cache))->render('held') === 'held', 'Concurrent render output changed.');
        break;

    case 'collector-held':
    case 'collector-follow':
        $policy = new TemplateCachePolicy(maxDeletions: 1);

        if ($scenario === 'collector-held') {
            $maintenance = new class($cache, $policy, static fn (): int => 200000) extends TemplateCacheMaintenance
            {
                protected function openDirectory(): mixed
                {
                    fwrite(STDERR, "READY\n");
                    stream_set_timeout(STDIN, 10);
                    check(trim((string) fgets(STDIN)) === 'release', 'Collector handshake timed out.');

                    return parent::openDirectory();
                }
            };
        } else {
            fwrite(STDERR, "ATTEMPT\n");
            $maintenance = new TemplateCacheMaintenance($cache, $policy, static fn (): int => 200000);
        }

        $maintenance->run(static fn (): string => 'rendered');
        break;

    case 'recompile':
        file_put_contents($views . DIRECTORY_SEPARATOR . 'page.arix.php', 'Current');
        $engine = new TemplateEngine($views, $cache, 'compatible-version');
        check($engine->render('page') === 'Current', 'Initial render failed.');
        $original = glob($cache . DIRECTORY_SEPARATOR . '*.php');
        touch($original[0], 100000);
        file_put_contents($cache . DIRECTORY_SEPARATOR . '.templates.lock', '');
        check($engine->render('page') === 'Current', 'Expired current template failed.');
        clearstatcache();
        check(glob($cache . DIRECTORY_SEPARATOR . '*.php') === $original, 'Compiled filename changed without content changes.');
        check(filemtime($original[0]) > 100000, 'Expired current template was not recompiled.');
        break;

    case 'native':
        file_put_contents($views . DIRECTORY_SEPARATOR . 'page.php', '<?php declare(strict_types=1); echo "Native";');
        check((new TemplateEngine($views, $root . DIRECTORY_SEPARATOR . 'missing-cache'))->render('page') === 'Native', 'Native rendering required compiled storage.');
        break;

    case 'nested':
        file_put_contents($views . DIRECTORY_SEPARATOR . 'outer.arix.php', "A<span>@include('native')</span>Z");
        file_put_contents($views . DIRECTORY_SEPARATOR . 'inner.arix.php', 'B');
        file_put_contents($views . DIRECTORY_SEPARATOR . 'other.arix.php', 'C');
        file_put_contents($views . DIRECTORY_SEPARATOR . 'native.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            $handle = fopen($cache . DIRECTORY_SEPARATOR . '.templates.lock', 'c+b');
            try {
                check(! flock($handle, LOCK_EX | LOCK_NB), 'Nested render lost its shared lock.');
            } finally {
                fclose($handle);
            }
            echo $this->partial('inner');
            echo $other->render('other');
            PHP);
        $engine = new TemplateEngine($views, $cache);
        $other = new TemplateEngine($views, $cache);
        $level = ob_get_level();
        $output = $engine->render('outer', ['cache' => $cache, 'other' => $other]);
        check($output === 'A<span>BC</span>Z', 'Nested rendering changed output: ' . var_export($output, true));
        file_put_contents($views . DIRECTORY_SEPARATOR . 'broken.arix.php', '<?php throw new RuntimeException("broken-template"); ?>');

        try {
            $engine->render('broken');
            throw new RuntimeException('Broken template did not throw.');
        } catch (RuntimeException $exception) {
            check(str_contains($exception->getMessage(), 'broken-template'), 'Unexpected render failure.');
        }

        check(ob_get_level() === $level, 'Render leaked output buffers.');
        $handle = fopen($cache . DIRECTORY_SEPARATOR . '.templates.lock', 'c+b');
        check(flock($handle, LOCK_EX | LOCK_NB), 'Render retained the shared lock after an exception.');
        fclose($handle);
        check($engine->render('inner') === 'B', 'Engine nesting state leaked after exception.');
        break;
    case 'collect':
        $policy = TemplateCachePolicy::fromConfiguration(['templates' => $options['policy'] ?? []]);
        $maintenance = new TemplateCacheMaintenance($cache, $policy, static fn (): int => $options['now']);
        check($maintenance->run(static function () use ($cache): string {
            $handle = fopen($cache . DIRECTORY_SEPARATOR . '.templates.lock', 'c+b');

            try {
                check(! flock($handle, LOCK_EX | LOCK_NB), 'Render was not protected by a shared lock.');
            } finally {
                fclose($handle);
            }

            return 'rendered';
        }) === 'rendered', 'Collection changed render output.');
        break;

    case 'rejected-lock':
    case 'rejected-directory':
        try {
            $maintenance = new TemplateCacheMaintenance($options['directory'] ?? $cache, new TemplateCachePolicy());
            $maintenance->run(static fn (): string => 'unsafe');
        } catch (RuntimeException) {
            break;
        }

        throw new RuntimeException('Unsafe cache or lock path was accepted.');
    case 'maintenance-failure':
        $failure = $options['failure'];
        $maintenance = new class($cache, new TemplateCachePolicy(), static fn (): int => 200000, $failure) extends TemplateCacheMaintenance
        {
            public function __construct(string $directory, TemplateCachePolicy $policy, Closure $clock, private readonly string $failure)
            {
                parent::__construct($directory, $policy, $clock);
            }

            protected function acquireLock(mixed $handle, int $operation): bool
            {

                return $this->failure !== 'lock' && parent::acquireLock($handle, $operation);
            }

            protected function openDirectory(): mixed
            {

                return $this->failure === 'directory' ? false : parent::openDirectory();
            }

            protected function deleteCompiled(string $path): bool
            {

                return false;
            }
        };

        $caught = null;
        $output = null;

        try {
            $output = $maintenance->run(static fn (): string => 'rendered');
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        if ($failure === 'lock') {
            check($caught instanceof RuntimeException && str_contains($caught->getMessage(), 'lock'), 'Unsafe render proceeded without a lock.');
        } else {
            check($caught === null && $output === 'rendered', 'GC failure altered output.');
        }

        break;
    case 'policy':
        $policy = TemplateCachePolicy::fromConfiguration([]);
        check($policy->cleanup && $policy->interval === 3600 && $policy->maxAge === 86400 && $policy->maxDeletions === 100, 'Incorrect defaults.');
        $policy = TemplateCachePolicy::fromConfiguration(['templates' => ['cleanup' => false, 'interval' => 1, 'max_age' => 2, 'max_deletions' => 3]]);
        check(! $policy->cleanup && $policy->interval === 1 && $policy->maxAge === 2 && $policy->maxDeletions === 3, 'Overrides were lost.');

        foreach ([null, false, 'invalid', 1] as $invalid) {
            rejectedPolicy(['templates' => $invalid]);
        }

        foreach (['interval', 'max_age', 'max_deletions'] as $key) {

            foreach ([null, true, false, 0, -1, '3600', 1.5, [], PHP_INT_MAX * 2.0] as $invalid) {
                rejectedPolicy(['templates' => [$key => $invalid]]);
            }
        }

        foreach ([null, 0, 1, 'false', []] as $invalid) {
            rejectedPolicy(['templates' => ['cleanup' => $invalid]]);
        }

        foreach ([0, -1] as $invalid) {

            try {
                new TemplateCachePolicy(interval: $invalid);
                throw new RuntimeException('Constructor accepted invalid interval.');
            } catch (InvalidArgumentException) {
                // Expected validation, not a TypeError from raw configuration.
            }
        }

        break;
    case 'autoload':
        check(! class_exists('Composer\Autoload\ClassLoader', false), 'Child must not load Composer.');
        check((new ReflectionClass(TemplateEngine::class))->getFileName() === realpath($argv[2]) . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'TemplateEngine.php', 'Wrong core loaded.');
        break;

    case 'changed-view':
        $file = $views . DIRECTORY_SEPARATOR . 'page.arix.php';
        file_put_contents($file, 'Version A');
        $engine = new TemplateEngine($views, $cache);
        check($engine->render('page') === 'Version A', 'First render failed.');
        $compiled = glob($cache . DIRECTORY_SEPARATOR . '*.php');
        check(count($compiled) === 1, 'Expected one initial compilation.');
        touch($compiled[0], time() - 90000);
        file_put_contents($cache . DIRECTORY_SEPARATOR . '.templates.lock', '');
        file_put_contents($file, 'Version B');
        check($engine->render('page') === 'Version B', 'Changed content was not rendered.');
        clearstatcache();
        check(! file_exists($compiled[0]), 'Old compilation was not reclaimed.');
        break;

    default:
        throw new RuntimeException("Unknown scenario: {$scenario}");
}

echo 'ok';

function rejectedPolicy(array $configuration): void
{

    try {
        TemplateCachePolicy::fromConfiguration($configuration);
    } catch (InvalidArgumentException) {

        return;
    }

    throw new RuntimeException('Invalid policy was accepted.');
}

function seedOldCompilations(string $cache, int $age = 100): array
{
    $paths = [];

    foreach (['old-one', 'old-two'] as $label) {
        $path = $cache . DIRECTORY_SEPARATOR . hash('sha256', $label) . '.php';
        file_put_contents($path, '<?php echo "unused";');
        touch($path, time() - $age);
        $paths[] = $path;
    }

    return $paths;
}

function remaining(array $paths): int
{
    clearstatcache();

    return count(array_filter($paths, is_file(...)));
}
