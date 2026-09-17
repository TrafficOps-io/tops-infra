<?php

namespace TrafficOps\LaravelCloud\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudManagerContract;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudNotFoundException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudRateLimitException;

abstract class AbstractCheckLaravelCloudDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $domainId) {}

    final public function handle(LaravelCloudManagerContract $manager): void
    {
        try {
            $manager->verifyDomain($this->domainId);
        } catch (LaravelCloudRateLimitException $exception) {
            $this->release($exception->retryAfter);
        } catch (LaravelCloudNotFoundException) {
            // The manager marks a remotely removed domain as detached.
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
