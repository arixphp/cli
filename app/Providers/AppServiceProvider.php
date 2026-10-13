<?php

declare(strict_types=1);

namespace App\Providers;

use App\Core\Update\CoreDistribution;
use App\Core\Update\CorePackager;
use App\Css\Update\CssDistribution;
use App\Installation\CurlTransport;
use App\Installation\GitHubSkeleton;
use App\Installation\HttpTransport;
use App\Installation\SkeletonSource;
use App\Js\Update\JsDistribution;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command !== 'app:build' || $event->input->hasParameterOption(['--help', '-h', '--version', '-V'], true)) {

                return;
            }

            $distribution = $this->app->make(CorePackager::class)->build();
            $event->output->writeln("Core bundle prepared: {$distribution->manifest['id']}");
        });
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CoreDistribution::class, fn (): CoreDistribution => CoreDistribution::bundled($this->app->basePath()));
        $this->app->bind(JsDistribution::class, fn (): JsDistribution => JsDistribution::bundled($this->app->basePath()));
        $this->app->bind(CssDistribution::class, fn (): CssDistribution => CssDistribution::bundled($this->app->basePath()));
        $this->app->bind(CorePackager::class, fn (): CorePackager => new CorePackager(
            $this->app->basePath('core'),
            $this->app->basePath('resources' . DIRECTORY_SEPARATOR . 'core.bundle'),
        ));
        $this->app->bind(HttpTransport::class, CurlTransport::class);
        $this->app->bind(SkeletonSource::class, GitHubSkeleton::class);
    }
}
