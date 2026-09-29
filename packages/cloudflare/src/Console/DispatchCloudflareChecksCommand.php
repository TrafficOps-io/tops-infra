<?php

namespace TrafficOps\Cloudflare\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use TrafficOps\Cloudflare\Jobs\AbstractCheckCloudflareIntegrationJob;
use TrafficOps\Cloudflare\Support\ModelResolver;

final class DispatchCloudflareChecksCommand extends Command
{
    protected $signature = 'cloudflare:dispatch-checks {--chunk=100 : Integrations loaded per database chunk}';

    protected $description = 'Dispatch one read-only Cloudflare check job per checkable integration';

    public function handle(): int
    {
        $jobClass = config('cloudflare.check_job');
        if (! is_string($jobClass) || ! is_subclass_of($jobClass, AbstractCheckCloudflareIntegrationJob::class)) {
            throw new InvalidArgumentException('cloudflare.check_job must extend AbstractCheckCloudflareIntegrationJob.');
        }

        $integrationClass = ModelResolver::class('integration');
        $count = 0;
        // Invalid integrations and expired tokens are skipped: nothing about them is checked until reconnected.
        $integrationClass::query()
            ->checkable()
            ->select('id')
            ->orderBy('id')
            ->chunkById(max(1, (int) $this->option('chunk')), function ($integrations) use ($jobClass, &$count) {
                foreach ($integrations as $integration) {
                    $jobClass::dispatch((string) $integration->getKey());
                    $count++;
                }
            });

        $this->info("Dispatched $count Cloudflare integration checks.");

        return self::SUCCESS;
    }
}
