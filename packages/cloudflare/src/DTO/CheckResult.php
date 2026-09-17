<?php

namespace TrafficOps\Cloudflare\DTO;

use TrafficOps\Cloudflare\Enums\IntegrationStatus;

final readonly class CheckResult
{
    /** @param list<DomainData> $domains */
    public function __construct(public string $integrationId, public IntegrationStatus $status, public array $domains) {}
}
