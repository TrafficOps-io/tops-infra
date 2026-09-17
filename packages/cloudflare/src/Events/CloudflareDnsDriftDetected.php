<?php

namespace TrafficOps\Cloudflare\Events;

use TrafficOps\Cloudflare\DTO\DomainData;

final readonly class CloudflareDnsDriftDetected
{
    public function __construct(public DomainData $domain) {}
}
