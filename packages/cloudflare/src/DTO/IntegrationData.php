<?php

namespace TrafficOps\Cloudflare\DTO;

use DateTimeInterface;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;

final readonly class IntegrationData
{
    public function __construct(
        public string $id,
        public ?string $label,
        public IntegrationStatus $status,
        public string $tokenStatus,
        public ?DateTimeInterface $lastVerifiedAt,
        public ?DateTimeInterface $lastSyncedAt,
    ) {}

    public static function fromModel(CloudflareIntegration $integration): self
    {
        return new self(
            (string) $integration->getKey(),
            $integration->label,
            $integration->status,
            $integration->token_status,
            $integration->last_verified_at,
            $integration->last_synced_at,
        );
    }
}
