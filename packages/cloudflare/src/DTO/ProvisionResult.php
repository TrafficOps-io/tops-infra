<?php

namespace TrafficOps\Cloudflare\DTO;

final readonly class ProvisionResult
{
    public function __construct(
        public string $domainId,
        public int $created,
        public int $updated,
        public int $adopted,
        public int $deleted,
    ) {}
}
