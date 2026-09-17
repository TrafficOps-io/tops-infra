<?php

namespace TrafficOps\Cloudflare\Services;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use TrafficOps\Cloudflare\Contracts\CloudflareClientContract;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\Dns\PublicDnsRecordChecker;
use TrafficOps\Cloudflare\DTO\AccountData;
use TrafficOps\Cloudflare\DTO\CheckResult;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\DTO\DomainData;
use TrafficOps\Cloudflare\DTO\DomainDefinition;
use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\DTO\ProvisionResult;
use TrafficOps\Cloudflare\DTO\ZoneData;
use TrafficOps\Cloudflare\Enums\DomainStatus;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Enums\RecordOwnership;
use TrafficOps\Cloudflare\Enums\RecordStatus;
use TrafficOps\Cloudflare\Events\CloudflareDnsDriftDetected;
use TrafficOps\Cloudflare\Events\CloudflareDomainAttached;
use TrafficOps\Cloudflare\Events\CloudflareDomainStatusChanged;
use TrafficOps\Cloudflare\Events\CloudflareIntegrationConnected;
use TrafficOps\Cloudflare\Events\CloudflareIntegrationStatusChanged;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflareConflictException;
use TrafficOps\Cloudflare\Exceptions\CloudflareNotFoundException;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;
use TrafficOps\Cloudflare\Models\CloudflareDomain;
use TrafficOps\Cloudflare\Models\CloudflareDomainRecord;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;
use TrafficOps\Cloudflare\Models\CloudflareZone;
use TrafficOps\Cloudflare\Support\Hostname;
use TrafficOps\Cloudflare\Support\ModelResolver;

final class CloudflareManager implements CloudflareManagerContract
{
    public function __construct(
        private readonly CloudflareClientContract $client,
        private readonly PublicDnsResolverContract $dns,
        private readonly CacheFactory $cache,
        private readonly Dispatcher $events,
    ) {}

    public function connect(Model $owner, string $apiToken, ?string $label = null): IntegrationData
    {
        $token = trim($apiToken);
        if ($token === '') {
            throw new CloudflareValidationException('Cloudflare API token cannot be empty.');
        }

        $verification = $this->client->verifyToken($token);
        if (($verification['status'] ?? null) !== 'active') {
            throw new CloudflareAuthenticationException('Cloudflare API token is not active.');
        }

        $zones = $this->client->listZones($token);
        if ($zones === []) {
            throw new CloudflarePermissionException('Cloudflare API token does not expose any zones.');
        }

        $this->client->listDnsRecords($token, (string) $zones[0]['id']);

        /** @var CloudflareIntegration $integration */
        $integration = DB::transaction(function () use ($owner, $token, $label, $verification, $zones) {
            $integrationClass = ModelResolver::class('integration');
            $fingerprint = hash('sha256', $token);

            /** @var CloudflareIntegration $integration */
            $integration = $integrationClass::query()->firstOrNew([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => (string) $owner->getKey(),
                'token_fingerprint' => $fingerprint,
            ]);
            $integration->fill([
                'label' => $label,
                'api_token' => $token,
                'token_id' => $verification['id'] ?? null,
                'token_status' => (string) $verification['status'],
                'status' => IntegrationStatus::Active,
                'token_expires_at' => isset($verification['expires_on']) ? Carbon::parse($verification['expires_on']) : null,
                'last_verified_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $this->persistZones($integration, $zones);

            return $integration->refresh();
        });

        $data = IntegrationData::fromModel($integration);
        $this->events->dispatch(new CloudflareIntegrationConnected($data));

        return $data;
    }

    public function sync(Model $owner, string $integrationId): IntegrationData
    {
        return IntegrationData::fromModel($this->syncIntegration($this->ownedIntegration($owner, $integrationId)));
    }

    public function accounts(Model $owner, string $integrationId): array
    {
        return $this->ownedIntegration($owner, $integrationId)
            ->accounts()->orderBy('name')->get()->map(AccountData::fromModel(...))->all();
    }

    public function zones(Model $owner, string $integrationId, ?string $accountId = null): array
    {
        $integration = $this->ownedIntegration($owner, $integrationId);
        $zoneClass = ModelResolver::class('zone');
        $query = $zoneClass::query()
            ->whereHas('account', fn ($query) => $query->where('integration_id', $integration->getKey()));

        if ($accountId !== null) {
            $query->where('account_id', $accountId);
        }

        return $query->orderBy('name')->get()->map(ZoneData::fromModel(...))->all();
    }

    public function domains(Model $owner, string $integrationId): array
    {
        return $this->integrationDomains($this->ownedIntegration($owner, $integrationId))
            ->map(DomainData::fromModel(...))->all();
    }

    public function disconnect(Model $owner, string $integrationId, bool $cleanupManagedRecords = false): void
    {
        $integration = $this->ownedIntegration($owner, $integrationId);

        if ($cleanupManagedRecords) {
            foreach ($this->integrationDomains($integration) as $domain) {
                $this->removeDomain($owner, (string) $domain->getKey(), true);
            }
            $integration->refresh();
        }

        $integration->delete();
    }

    public function attachDomain(Model $owner, string $integrationId, string $zoneId, DomainDefinition $definition): DomainData
    {
        $integration = $this->ownedIntegration($owner, $integrationId);
        $zone = $this->ownedZone($integration, $zoneId);

        if (! Hostname::belongsToZone($definition->hostname, $zone->name)) {
            throw new CloudflareValidationException("Domain [{$definition->hostname}] is outside zone [{$zone->name}].");
        }
        $this->validateExpectations($definition->records, $zone->name);

        $store = $this->cache->store(config('cloudflare.claim_lock_store'));

        try {
            /** @var CloudflareDomain $domain */
            $domain = $store->lock('cloudflare:domain-claims', (int) config('cloudflare.claim_lock_seconds', 10))
                ->block(5, function () use ($owner, $zone, $definition) {
                    $domainClass = ModelResolver::class('domain');
                    $domains = $domainClass::query()->with('zone.account.integration')->get();

                    foreach ($domains as $existing) {
                        $sameOwner = $existing->zone->account->integration->owner_type === $owner->getMorphClass()
                            && (string) $existing->zone->account->integration->owner_id === (string) $owner->getKey();

                        if ($existing->hostname === $definition->hostname || (! $sameOwner && Hostname::overlaps($existing->hostname, $definition->hostname))) {
                            throw new CloudflareConflictException("Domain claim [{$definition->hostname}] conflicts with [{$existing->hostname}].");
                        }
                    }

                    return DB::transaction(function () use ($zone, $definition, $domainClass) {
                        /** @var CloudflareDomain $domain */
                        $domain = $domainClass::query()->create([
                            'zone_id' => $zone->getKey(),
                            'hostname' => $definition->hostname,
                            'kind' => $definition->isWildcard() ? 'wildcard' : 'exact',
                            'status' => DomainStatus::Pending,
                        ]);
                        $this->storeExpectations($domain, $definition->records);

                        return $domain;
                    });
                });
        } catch (LockTimeoutException $exception) {
            throw new CloudflareConflictException('Could not acquire the global domain claim lock.', previous: $exception);
        }

        $data = DomainData::fromModel($domain);
        $this->events->dispatch(new CloudflareDomainAttached($data));

        return $data;
    }

    public function replaceDomainExpectations(Model $owner, string $domainId, array $records): DomainData
    {
        if ($records === []) {
            throw new CloudflareValidationException('A domain needs at least one expected DNS record.');
        }

        $domain = $this->ownedDomain($owner, $domainId);
        $this->validateExpectations($records, $domain->zone->name);

        DB::transaction(function () use ($domain, $records) {
            $domain->records()->update(['desired' => false]);
            $this->storeExpectations($domain, $records);
            $domain->update(['status' => DomainStatus::Pending, 'last_error_code' => null, 'last_error_message' => null]);
        });

        return DomainData::fromModel($domain->refresh());
    }

    public function removeDomain(Model $owner, string $domainId, bool $cleanupManagedRecords = false): void
    {
        $domain = $this->ownedDomain($owner, $domainId);

        if ($cleanupManagedRecords) {
            $integration = $domain->zone->account->integration;
            foreach ($domain->records()->where('ownership', RecordOwnership::Managed->value)->get() as $record) {
                if ($record->cloudflare_record_id !== null) {
                    try {
                        $this->client->deleteDnsRecord($integration->api_token, $domain->zone->cloudflare_id, $record->cloudflare_record_id);
                    } catch (CloudflareNotFoundException) {
                        // The desired remote state has already been reached.
                    }
                }
            }
        }

        $domain->delete();
    }

    public function reconcileDomain(Model $owner, string $domainId): ProvisionResult
    {
        return $this->reconcile($this->ownedDomain($owner, $domainId));
    }

    public function checkIntegration(Model $owner, string $integrationId): CheckResult
    {
        return $this->check($this->ownedIntegration($owner, $integrationId));
    }

    public function checkIntegrationById(string $integrationId): CheckResult
    {
        return $this->check($this->findIntegration($integrationId));
    }

    private function reconcile(CloudflareDomain $domain): ProvisionResult
    {
        $domain->loadMissing('zone.account.integration', 'records');
        $integration = $domain->zone->account->integration;
        $token = $integration->api_token;
        $zoneId = $domain->zone->cloudflare_id;
        $remote = $this->client->listDnsRecords($token, $zoneId);
        $created = $updated = $adopted = $deleted = 0;
        $staleRecords = $domain->records->where('desired', false)->values();
        $desired = $domain->records()->where('desired', true)->get();
        foreach ($desired as $record) {
            $expectation = $record->expectation();
            $match = collect($remote)->first(fn (array $candidate) => $expectation->matches($candidate));

            if ($match !== null) {
                $wasManaged = $record->ownership === RecordOwnership::Managed
                    && $record->cloudflare_record_id === ($match['id'] ?? null);
                $wasAdopted = $record->ownership === RecordOwnership::Adopted
                    && $record->cloudflare_record_id === ($match['id'] ?? null);
                $record->update([
                    'cloudflare_record_id' => $match['id'] ?? null,
                    'ownership' => $wasManaged ? RecordOwnership::Managed : RecordOwnership::Adopted,
                    'control_status' => RecordStatus::Matched,
                    'last_error_message' => null,
                ]);
                if (! $wasManaged && ! $wasAdopted) {
                    $adopted++;
                }

                continue;
            }

            $replaceable = $staleRecords->first(fn (CloudflareDomainRecord $stale) => $stale->ownership === RecordOwnership::Managed
                && $stale->cloudflare_record_id !== null
                && $stale->type === $expectation->type
                && $stale->name === $expectation->name
                && collect($remote)->contains(fn (array $candidate) => ($candidate['id'] ?? null) === $stale->cloudflare_record_id));

            if ($replaceable !== null) {
                try {
                    $updatedRecord = $this->client->updateDnsRecord(
                        $token,
                        $zoneId,
                        $replaceable->cloudflare_record_id,
                        $expectation->toArray(),
                    );
                } catch (CloudflareTransportException $exception) {
                    $updatedRecord = collect($this->client->listDnsRecords($token, $zoneId))
                        ->first(fn (array $candidate) => ($candidate['id'] ?? null) === $replaceable->cloudflare_record_id
                            && $expectation->matches($candidate));
                    if ($updatedRecord === null) {
                        throw $exception;
                    }
                }

                $updatedRecord = ['id' => $replaceable->cloudflare_record_id, ...$expectation->toArray(), ...$updatedRecord];
                $record->update([
                    'cloudflare_record_id' => $replaceable->cloudflare_record_id,
                    'ownership' => RecordOwnership::Managed,
                    'control_status' => RecordStatus::Matched,
                    'last_error_message' => null,
                ]);
                $replaceable->delete();
                $staleRecords = $staleRecords->reject(fn (CloudflareDomainRecord $stale) => $stale->is($replaceable))->values();
                $remote = collect($remote)->map(fn (array $candidate) => ($candidate['id'] ?? null) === $replaceable->cloudflare_record_id
                    ? $updatedRecord
                    : $candidate)->all();
                $updated++;

                continue;
            }

            $otherDesiredMatches = collect($remote)->filter(function (array $candidate) use ($desired) {
                return $desired->contains(fn (CloudflareDomainRecord $expected) => $expected->expectation()->matches($candidate));
            });
            $conflict = collect($remote)->first(fn (array $candidate) => strtoupper((string) ($candidate['type'] ?? '')) === $expectation->type
                && Hostname::normalize((string) ($candidate['name'] ?? ''), allowWildcard: true, allowUnderscore: true) === $expectation->name
                && ! $otherDesiredMatches->contains(fn (array $expected) => ($expected['id'] ?? null) === ($candidate['id'] ?? null))
            );

            if ($conflict !== null) {
                throw new CloudflareConflictException("Cloudflare already contains a conflicting {$expectation->type} record for [{$expectation->name}].");
            }

            try {
                $createdRecord = $this->client->createDnsRecord($token, $zoneId, $expectation->toArray());
            } catch (CloudflareTransportException $exception) {
                $createdRecord = collect($this->client->listDnsRecords($token, $zoneId))
                    ->first(fn (array $candidate) => $expectation->matches($candidate));
                if ($createdRecord === null) {
                    throw $exception;
                }
            }

            if (! isset($createdRecord['id'])) {
                throw new CloudflareTransportException('Cloudflare did not return an ID for the created DNS record.');
            }

            $record->update([
                'cloudflare_record_id' => $createdRecord['id'],
                'ownership' => RecordOwnership::Managed,
                'control_status' => RecordStatus::Matched,
                'last_error_message' => null,
            ]);
            $remote[] = $createdRecord;
            $created++;
        }

        foreach ($staleRecords as $stale) {
            if ($stale->ownership === RecordOwnership::Managed && $stale->cloudflare_record_id !== null) {
                try {
                    $this->client->deleteDnsRecord($token, $zoneId, $stale->cloudflare_record_id);
                    $deleted++;
                } catch (CloudflareNotFoundException) {
                    // Deletion is idempotent.
                }
            }
            $stale->delete();
        }

        $domain->update(['status' => DomainStatus::Pending, 'last_error_code' => null, 'last_error_message' => null]);

        return new ProvisionResult((string) $domain->getKey(), $created, $updated, $adopted, $deleted);
    }

    private function check(CloudflareIntegration $integration): CheckResult
    {
        $integration = $this->syncIntegration($integration, activate: false);
        $domains = $this->integrationDomains($integration);
        $remoteByZone = [];
        $results = [];
        $degraded = false;

        foreach ($domains as $domain) {
            $zoneKey = (string) $domain->zone->getKey();
            try {
                $remoteByZone[$zoneKey] ??= $this->client->listDnsRecords($integration->api_token, $domain->zone->cloudflare_id);
                $status = $this->checkDomain($domain, $remoteByZone[$zoneKey]);
            } catch (CloudflarePermissionException $exception) {
                $this->transitionDomain($domain, DomainStatus::Error, 'permission', $exception->getMessage());
                $this->transitionIntegration($integration, IntegrationStatus::Degraded, 'permission', $exception->getMessage());
                throw $exception;
            } catch (CloudflareTransportException $exception) {
                $this->transitionDomain($domain, DomainStatus::Unreachable, 'transport', $exception->getMessage());
                $this->transitionIntegration($integration, IntegrationStatus::Unreachable, 'transport', $exception->getMessage());
                throw $exception;
            }

            $degraded = $degraded || in_array($status, [DomainStatus::Drifted, DomainStatus::Error, DomainStatus::Unreachable], true);
            $results[] = DomainData::fromModel($domain->refresh());
        }

        $this->transitionIntegration($integration, $degraded ? IntegrationStatus::Degraded : IntegrationStatus::Active);

        return new CheckResult((string) $integration->getKey(), $integration->refresh()->status, $results);
    }

    /** @param list<array<string, mixed>> $remote */
    private function checkDomain(CloudflareDomain $domain, array $remote): DomainStatus
    {
        $domain->loadMissing('records');
        $hasControlDrift = false;
        $hasPublicPending = false;
        $hasPublicFailure = false;

        foreach ($domain->records->where('desired', true) as $record) {
            $expectation = $record->expectation();
            $match = collect($remote)->first(fn (array $candidate) => $expectation->matches($candidate));
            $sameKey = collect($remote)->contains(fn (array $candidate) => strtoupper((string) ($candidate['type'] ?? '')) === $expectation->type
                && Hostname::normalize((string) ($candidate['name'] ?? ''), allowWildcard: true, allowUnderscore: true) === $expectation->name
            );
            $control = $match !== null ? RecordStatus::Matched : ($sameKey ? RecordStatus::Mismatched : RecordStatus::Missing);
            $hasControlDrift = $hasControlDrift || $control !== RecordStatus::Matched;

            try {
                $public = $this->checkPublicDns($domain, $expectation);
                $hasPublicPending = $hasPublicPending || $public !== RecordStatus::Matched;
                $errorMessage = null;
            } catch (CloudflareTransportException $exception) {
                $public = RecordStatus::Unreachable;
                $hasPublicFailure = true;
                $errorMessage = $exception->getMessage();
            }

            $record->fill([
                'control_status' => $control,
                'public_status' => $public,
                'last_checked_at' => now(),
                'last_error_message' => $errorMessage,
            ])->save();
        }

        $status = match (true) {
            $hasControlDrift => DomainStatus::Drifted,
            $hasPublicFailure => DomainStatus::Unreachable,
            $hasPublicPending => DomainStatus::PendingPropagation,
            default => DomainStatus::Active,
        };
        $this->transitionDomain($domain, $status);

        return $status;
    }

    private function checkPublicDns(CloudflareDomain $domain, DnsRecordExpectation $expectation): RecordStatus
    {
        $result = (new PublicDnsRecordChecker($this->dns))->check(
            $expectation,
            (string) $domain->getKey(),
            allowFlattening: $expectation->name === $domain->zone->name,
        );

        return match ($result['status']) {
            'matched' => RecordStatus::Matched,
            'mismatched' => RecordStatus::Mismatched,
            'indeterminate' => RecordStatus::Pending,
            default => RecordStatus::Missing,
        };
    }

    private function syncIntegration(CloudflareIntegration $integration, bool $activate = true): CloudflareIntegration
    {
        $previous = $integration->status;

        try {
            $verification = $this->client->verifyToken($integration->api_token);
            if (($verification['status'] ?? null) !== 'active') {
                throw new CloudflareAuthenticationException('Cloudflare API token is not active.');
            }
            $zones = $this->client->listZones($integration->api_token);
            if ($zones === []) {
                DB::transaction(fn () => $this->persistZones($integration, []));
                throw new CloudflarePermissionException('Cloudflare API token does not expose any zones.');
            }
            DB::transaction(function () use ($activate, $integration, $verification, $zones) {
                $attributes = [
                    'token_id' => $verification['id'] ?? $integration->token_id,
                    'token_status' => (string) $verification['status'],
                    'token_expires_at' => isset($verification['expires_on']) ? Carbon::parse($verification['expires_on']) : null,
                    'last_verified_at' => now(),
                ];
                if ($activate) {
                    $attributes += [
                        'status' => IntegrationStatus::Active,
                        'last_error_code' => null,
                        'last_error_message' => null,
                    ];
                }
                $integration->update($attributes);
                $this->persistZones($integration, $zones);
            });
        } catch (CloudflareAuthenticationException $exception) {
            $this->transitionIntegration($integration, IntegrationStatus::Invalid, 'authentication', $exception->getMessage());
            throw $exception;
        } catch (CloudflarePermissionException $exception) {
            $this->transitionIntegration($integration, IntegrationStatus::Degraded, 'permission', $exception->getMessage());
            throw $exception;
        } catch (CloudflareTransportException $exception) {
            $this->transitionIntegration($integration, IntegrationStatus::Unreachable, 'transport', $exception->getMessage());
            throw $exception;
        }

        if ($activate && $previous !== $integration->refresh()->status) {
            $this->events->dispatch(new CloudflareIntegrationStatusChanged(IntegrationData::fromModel($integration), $previous));
        }

        return $integration;
    }

    /** @param list<array<string, mixed>> $zones */
    private function persistZones(CloudflareIntegration $integration, array $zones): void
    {
        $now = now();
        $integration->accounts()->update(['status' => 'inaccessible']);
        foreach ($integration->accounts as $account) {
            $account->zones()->update(['status' => 'inaccessible']);
        }

        foreach ($zones as $remoteZone) {
            $accountData = (array) ($remoteZone['account'] ?? []);
            if (! isset($accountData['id'])) {
                continue;
            }
            $account = $integration->accounts()->updateOrCreate(
                ['cloudflare_id' => (string) $accountData['id']],
                ['name' => (string) ($accountData['name'] ?? $accountData['id']), 'status' => 'active', 'last_synced_at' => $now],
            );
            $account->zones()->updateOrCreate(
                ['cloudflare_id' => (string) $remoteZone['id']],
                [
                    'name' => Hostname::normalize((string) $remoteZone['name']),
                    'status' => (string) ($remoteZone['status'] ?? 'unknown'),
                    'type' => $remoteZone['type'] ?? null,
                    'paused' => (bool) ($remoteZone['paused'] ?? false),
                    'name_servers' => $remoteZone['name_servers'] ?? null,
                    'last_synced_at' => $now,
                ],
            );
        }
        $integration->update(['last_synced_at' => $now]);
    }

    /** @param list<DnsRecordExpectation> $records */
    private function storeExpectations(CloudflareDomain $domain, array $records): void
    {
        foreach ($records as $expectation) {
            $domain->records()->updateOrCreate(
                ['signature' => $expectation->signature()],
                [...$expectation->toArray(), 'desired' => true],
            );
        }
    }

    /** @param list<DnsRecordExpectation> $records */
    private function validateExpectations(array $records, string $zone): void
    {
        $signatures = [];
        foreach ($records as $record) {
            if (! $record instanceof DnsRecordExpectation) {
                throw new CloudflareValidationException('Domain records must be DnsRecordExpectation instances.');
            }
            if (! Hostname::belongsToZone($record->name, $zone)) {
                throw new CloudflareValidationException("DNS record [{$record->name}] is outside zone [$zone].");
            }
            if (isset($signatures[$record->signature()])) {
                throw new CloudflareValidationException('Duplicate DNS record expectation.');
            }
            $signatures[$record->signature()] = true;
        }
    }

    private function transitionDomain(CloudflareDomain $domain, DomainStatus $status, ?string $code = null, ?string $message = null): void
    {
        $previous = $domain->status;
        $domain->update([
            'status' => $status,
            'last_checked_at' => now(),
            'last_error_code' => $code,
            'last_error_message' => $message,
        ]);
        if ($previous !== $status) {
            $data = DomainData::fromModel($domain->refresh());
            $this->events->dispatch(new CloudflareDomainStatusChanged($data, $previous));
            if ($status === DomainStatus::Drifted) {
                $this->events->dispatch(new CloudflareDnsDriftDetected($data));
            }
        }
    }

    private function transitionIntegration(CloudflareIntegration $integration, IntegrationStatus $status, ?string $code = null, ?string $message = null): void
    {
        $previous = $integration->status;
        $integration->update(['status' => $status, 'last_error_code' => $code, 'last_error_message' => $message]);
        if ($previous !== $status) {
            $this->events->dispatch(new CloudflareIntegrationStatusChanged(IntegrationData::fromModel($integration->refresh()), $previous));
        }
    }

    private function ownedIntegration(Model $owner, string $id): CloudflareIntegration
    {
        $integration = $this->findIntegration($id);
        if ($integration->owner_type !== $owner->getMorphClass() || (string) $integration->owner_id !== (string) $owner->getKey()) {
            throw new CloudflareNotFoundException('Cloudflare integration not found for this owner.');
        }

        return $integration;
    }

    private function findIntegration(string $id): CloudflareIntegration
    {
        $class = ModelResolver::class('integration');
        $integration = $class::query()->find($id);

        return $integration ?? throw new CloudflareNotFoundException('Cloudflare integration not found.');
    }

    private function ownedZone(CloudflareIntegration $integration, string $id): CloudflareZone
    {
        $zoneClass = ModelResolver::class('zone');
        $zone = $zoneClass::query()->whereKey($id)->whereHas('account', fn ($query) => $query->where('integration_id', $integration->getKey()))->first();

        return $zone ?? throw new CloudflareNotFoundException('Cloudflare zone not found for this integration.');
    }

    private function ownedDomain(Model $owner, string $id): CloudflareDomain
    {
        $domainClass = ModelResolver::class('domain');
        $domain = $domainClass::query()->with('zone.account.integration', 'records')->find($id);
        if ($domain === null
            || $domain->zone->account->integration->owner_type !== $owner->getMorphClass()
            || (string) $domain->zone->account->integration->owner_id !== (string) $owner->getKey()) {
            throw new CloudflareNotFoundException('Cloudflare domain not found for this owner.');
        }

        return $domain;
    }

    /** @return Collection<int, CloudflareDomain> */
    private function integrationDomains(CloudflareIntegration $integration): Collection
    {
        $domainClass = ModelResolver::class('domain');

        return $domainClass::query()
            ->with('zone.account.integration', 'records')
            ->whereHas('zone.account', fn ($query) => $query->where('integration_id', $integration->getKey()))
            ->get();
    }
}
