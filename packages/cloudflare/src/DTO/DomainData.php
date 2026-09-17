<?php

namespace TrafficOps\Cloudflare\DTO;

use DateTimeInterface;
use TrafficOps\Cloudflare\Enums\DomainStatus;
use TrafficOps\Cloudflare\Models\CloudflareDomain;

final readonly class DomainData
{
    public function __construct(
        public string $id,
        public string $hostname,
        public string $kind,
        public DomainStatus $status,
        public ?DateTimeInterface $lastCheckedAt,
    ) {}

    public static function fromModel(CloudflareDomain $domain): self
    {
        return new self((string) $domain->getKey(), $domain->hostname, $domain->kind, $domain->status, $domain->last_checked_at);
    }
}
