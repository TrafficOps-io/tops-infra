<?php

namespace TrafficOps\LaravelCloud\DTO;

use DateTimeInterface;
use TrafficOps\LaravelCloud\Enums\DomainStatus;
use TrafficOps\LaravelCloud\Models\LaravelCloudDomain;

final readonly class DomainData
{
    /** @param array<string, mixed> $variantStatuses @param list<DnsRecordData> $dnsRecords */
    public function __construct(
        public string $id,
        public string $remoteId,
        public string $environmentId,
        public string $name,
        public bool $wildcardEnabled,
        public DomainStatus $status,
        public ?string $hostnameStatus,
        public ?string $sslStatus,
        public ?string $originStatus,
        public array $variantStatuses,
        public ?string $actionRequired,
        public ?string $wwwRedirect,
        public ?bool $allowDowntime,
        public ?string $cloudflareStrategy,
        public ?string $verificationMethod,
        public array $dnsRecords,
        public array $remotePayload,
        public ?DateTimeInterface $lastVerifiedAt,
        public ?DateTimeInterface $lastSyncedAt,
    ) {}

    public static function fromModel(LaravelCloudDomain $domain): self
    {
        $domain->loadMissing('dnsRecords');

        return new self(
            (string) $domain->getKey(),
            $domain->laravel_cloud_id,
            $domain->environment_id,
            $domain->name,
            $domain->wildcard_enabled,
            $domain->status,
            $domain->hostname_status,
            $domain->ssl_status,
            $domain->origin_status,
            $domain->variant_statuses ?? [],
            $domain->action_required,
            $domain->www_redirect,
            $domain->allow_downtime,
            $domain->cloudflare_strategy,
            $domain->verification_method,
            $domain->dnsRecords->map(DnsRecordData::fromModel(...))->all(),
            $domain->remote_payload,
            $domain->last_verified_at,
            $domain->last_synced_at,
        );
    }
}
