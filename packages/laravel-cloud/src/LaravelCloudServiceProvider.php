<?php

namespace TrafficOps\LaravelCloud;

use Illuminate\Support\ServiceProvider;
use TrafficOps\LaravelCloud\Console\DispatchLaravelCloudDomainChecksCommand;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudClientContract;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudManagerContract;
use TrafficOps\LaravelCloud\Http\LaravelHttpCloudClient;
use TrafficOps\LaravelCloud\Services\LaravelCloudManager;

final class LaravelCloudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-cloud.php', 'laravel-cloud');
        $this->app->singleton(LaravelCloudClientContract::class, LaravelHttpCloudClient::class);
        $this->app->singleton(LaravelCloudManagerContract::class, LaravelCloudManager::class);
        $this->app->alias(LaravelCloudManagerContract::class, 'laravel-cloud');
    }

    public function boot(): void
    {
        if ((bool) config('laravel-cloud.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../config/laravel-cloud.php' => config_path('laravel-cloud.php'),
        ], 'laravel-cloud-config');

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchLaravelCloudDomainChecksCommand::class]);
        }
    }
}
