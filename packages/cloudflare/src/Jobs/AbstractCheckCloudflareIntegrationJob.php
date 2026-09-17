<?php

namespace TrafficOps\Cloudflare\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;

abstract class AbstractCheckCloudflareIntegrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $integrationId) {}

    final public function handle(CloudflareManagerContract $manager): void
    {
        try {
            $manager->checkIntegrationById($this->integrationId);
        } catch (CloudflareRateLimitException $exception) {
            $this->release($exception->retryAfter);
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }
}
