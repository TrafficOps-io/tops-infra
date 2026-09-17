<?php

namespace TrafficOps\Cloudflare\Contracts;

use Illuminate\Database\Eloquent\Model;
use TrafficOps\Cloudflare\DTO\AccountData;
use TrafficOps\Cloudflare\DTO\CheckResult;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\DTO\DomainData;
use TrafficOps\Cloudflare\DTO\DomainDefinition;
use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\DTO\ProvisionResult;
use TrafficOps\Cloudflare\DTO\ZoneData;

interface CloudflareManagerContract
{
    public function connect(Model $owner, string $apiToken, ?string $label = null): IntegrationData;

    public function sync(Model $owner, string $integrationId): IntegrationData;

    /** @return list<AccountData> */
    public function accounts(Model $owner, string $integrationId): array;

    /** @return list<ZoneData> */
    public function zones(Model $owner, string $integrationId, ?string $accountId = null): array;

    /** @return list<DomainData> */
    public function domains(Model $owner, string $integrationId): array;

    public function disconnect(Model $owner, string $integrationId, bool $cleanupManagedRecords = false): void;

    public function attachDomain(Model $owner, string $integrationId, string $zoneId, DomainDefinition $definition): DomainData;

    /** @param list<DnsRecordExpectation> $records */
    public function replaceDomainExpectations(Model $owner, string $domainId, array $records): DomainData;

    public function removeDomain(Model $owner, string $domainId, bool $cleanupManagedRecords = false): void;

    public function reconcileDomain(Model $owner, string $domainId): ProvisionResult;

    public function checkIntegration(Model $owner, string $integrationId): CheckResult;

    public function checkIntegrationById(string $integrationId): CheckResult;
}
