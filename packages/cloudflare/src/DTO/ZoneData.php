<?php

namespace TrafficOps\Cloudflare\DTO;

use TrafficOps\Cloudflare\Models\CloudflareZone;

final readonly class ZoneData
{
    public function __construct(public string $id, public string $cloudflareId, public string $name, public string $status, public bool $paused) {}

    public static function fromModel(CloudflareZone $zone): self
    {
        return new self((string) $zone->getKey(), $zone->cloudflare_id, $zone->name, $zone->status, $zone->paused);
    }
}
