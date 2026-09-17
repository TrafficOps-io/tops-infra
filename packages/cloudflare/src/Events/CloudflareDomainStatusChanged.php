<?php

namespace TrafficOps\Cloudflare\Events;

use TrafficOps\Cloudflare\DTO\DomainData;
use TrafficOps\Cloudflare\Enums\DomainStatus;

final readonly class CloudflareDomainStatusChanged
{
    public function __construct(public DomainData $domain, public DomainStatus $previousStatus) {}
}
