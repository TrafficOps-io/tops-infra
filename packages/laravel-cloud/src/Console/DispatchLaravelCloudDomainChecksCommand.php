<?php

namespace TrafficOps\LaravelCloud\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use TrafficOps\LaravelCloud\Enums\DomainStatus;
use TrafficOps\LaravelCloud\Jobs\AbstractCheckLaravelCloudDomainJob;
use TrafficOps\LaravelCloud\Support\ModelResolver;

final class DispatchLaravelCloudDomainChecksCommand extends Command
{
    protected $signature = 'laravel-cloud:dispatch-domain-checks {--chunk=100 : Domains loaded per database chunk}';

    protected $description = 'Dispatch one Laravel Cloud verification job per pending domain';

    public function handle(): int
    {
        $jobClass = config('laravel-cloud.check_job');
        if (! is_string($jobClass) || ! is_subclass_of($jobClass, AbstractCheckLaravelCloudDomainJob::class)) {
            throw new InvalidArgumentException('laravel-cloud.check_job must extend AbstractCheckLaravelCloudDomainJob.');
        }

        $domainClass = ModelResolver::class();
        $environmentId = trim((string) config('laravel-cloud.api.environment_id'));
        if ($environmentId === '') {
            throw new InvalidArgumentException('laravel-cloud.api.environment_id must be configured.');
        }

        $count = 0;
        $domainClass::query()
            ->select('id')
            ->where('environment_id', $environmentId)
            ->where('status', DomainStatus::Pending->value)
            ->orderBy('id')
            ->chunkById(max(1, (int) $this->option('chunk')), function ($domains) use ($jobClass, &$count) {
                foreach ($domains as $domain) {
                    $jobClass::dispatch((string) $domain->getKey());
                    $count++;
                }
            });

        $this->info("Dispatched $count Laravel Cloud domain checks.");

        return self::SUCCESS;
    }
}
