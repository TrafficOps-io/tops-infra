<?php

namespace TrafficOps\Cloudflare\Events;

use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;

final readonly class CloudflareIntegrationStatusChanged
{
    public function __construct(public IntegrationData $integration, public IntegrationStatus $previousStatus) {}
}
