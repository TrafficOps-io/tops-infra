<?php

namespace TrafficOps\Cloudflare\Events;

use TrafficOps\Cloudflare\DTO\IntegrationData;

final readonly class CloudflareIntegrationConnected
{
    public function __construct(public IntegrationData $integration) {}
}
