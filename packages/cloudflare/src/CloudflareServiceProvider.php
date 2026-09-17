<?php

namespace TrafficOps\Cloudflare;

use Illuminate\Support\ServiceProvider;
use TrafficOps\Cloudflare\Console\DispatchCloudflareChecksCommand;
use TrafficOps\Cloudflare\Contracts\CloudflareClientContract;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\Dns\CloudflareDohResolver;
use TrafficOps\Cloudflare\Http\LaravelCloudflareClient;
use TrafficOps\Cloudflare\Services\CloudflareManager;

final class CloudflareServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cloudflare.php', 'cloudflare');
        $this->app->singleton(CloudflareClientContract::class, LaravelCloudflareClient::class);
        $this->app->singleton(PublicDnsResolverContract::class, CloudflareDohResolver::class);
        $this->app->singleton(CloudflareManagerContract::class, CloudflareManager::class);
        $this->app->alias(CloudflareManagerContract::class, 'cloudflare');
    }

    public function boot(): void
    {
        if ((bool) config('cloudflare.migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        $this->publishes([
            __DIR__.'/../config/cloudflare.php' => config_path('cloudflare.php'),
        ], 'cloudflare-config');

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchCloudflareChecksCommand::class]);
        }
    }
}
