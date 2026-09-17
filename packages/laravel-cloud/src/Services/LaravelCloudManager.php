<?php

namespace TrafficOps\LaravelCloud\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudClientContract;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudManagerContract;
use TrafficOps\LaravelCloud\DTO\DomainData;
use TrafficOps\LaravelCloud\DTO\DomainOptions;
use TrafficOps\LaravelCloud\Enums\DomainStatus;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudConflictException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudNotFoundException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudValidationException;
use TrafficOps\LaravelCloud\Models\LaravelCloudDomain;
use TrafficOps\LaravelCloud\Support\Hostname;
use TrafficOps\LaravelCloud\Support\ModelResolver;

final class LaravelCloudManager implements LaravelCloudManagerContract
{
    private const DNS_PURPOSES = ['ssl', 'pre_verification', 'origin', 'origin_cname', 'dcv'];

    public function __construct(private readonly LaravelCloudClientContract $client) {}

    public function domains(): array
    {
        $environmentId = $this->environmentId();
        $summaries = $this->client->listDomains($environmentId);
        $seen = [];
        $domains = [];

        foreach ($summaries as $summary) {
            $remoteId = (string) ($summary['id'] ?? '');
            if ($remoteId === '') {
                continue;
            }

            try {
                $domain = $this->persist($this->client->getDomain($remoteId), checked: false);
            } catch (LaravelCloudNotFoundException) {
                continue;
            }

            $seen[] = $remoteId;
            $domains[] = DomainData::fromModel($domain);
        }

        $query = $this->domainQuery()->where('environment_id', $environmentId);
        if ($seen === []) {
            $query->update(['status' => DomainStatus::Detached->value, 'last_synced_at' => now()]);
        } else {
            $query->whereNotIn('laravel_cloud_id', $seen)
                ->update(['status' => DomainStatus::Detached->value, 'last_synced_at' => now()]);
        }

        return $domains;
    }

    public function createExactDomain(string $name, ?Model $attachable = null, ?DomainOptions $options = null): DomainData
    {
        return $this->create(Hostname::exact($name), false, $attachable, $options ?? DomainOptions::exact());
    }

    public function createWildcardDomain(string $name, ?Model $attachable = null, ?DomainOptions $options = null): DomainData
    {
        return $this->create(Hostname::wildcardBase($name), true, $attachable, $options ?? DomainOptions::wildcard());
    }

    public function domainStatus(string $localDomainId): DomainData
    {
        $domain = $this->localDomain($localDomainId);

        try {
            return DomainData::fromModel($this->persist($this->client->getDomain($domain->laravel_cloud_id), checked: true));
        } catch (LaravelCloudNotFoundException $exception) {
            $domain->update([
                'status' => DomainStatus::Detached,
                'last_checked_at' => now(),
                'last_error_code' => '404',
                'last_error_message' => $exception->getMessage(),
            ]);
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordError($domain, $exception);
            throw $exception;
        }
    }

    public function verifyDomain(string $localDomainId): DomainData
    {
        $domain = $this->localDomain($localDomainId);

        try {
            return DomainData::fromModel($this->persist($this->client->verifyDomain($domain->laravel_cloud_id), checked: true));
        } catch (LaravelCloudNotFoundException $exception) {
            $domain->update([
                'status' => DomainStatus::Detached,
                'last_checked_at' => now(),
                'last_error_code' => '404',
                'last_error_message' => $exception->getMessage(),
            ]);
            throw $exception;
        } catch (Throwable $exception) {
            $this->recordError($domain, $exception);
            throw $exception;
        }
    }

    public function attachDomain(string $localDomainId, Model $attachable): DomainData
    {
        $this->assertPersisted($attachable);

        return DB::transaction(function () use ($localDomainId, $attachable) {
            $domain = $this->domainQuery()->where('environment_id', $this->environmentId())->lockForUpdate()->find($localDomainId);
            if ($domain === null) {
                throw new LaravelCloudNotFoundException('The local domain was not found.', 404);
            }
            $type = $attachable->getMorphClass();
            $id = (string) $attachable->getKey();
            if ($domain->attachable_type !== null
                && ($domain->attachable_type !== $type || (string) $domain->attachable_id !== $id)) {
                throw new LaravelCloudConflictException('The domain is already attached to another owner.');
            }
            $domain->update(['attachable_type' => $type, 'attachable_id' => $id]);

            return DomainData::fromModel($domain->refresh());
        });
    }

    private function create(string $name, bool $wildcard, ?Model $attachable, DomainOptions $options): DomainData
    {
        if ($attachable !== null) {
            $this->assertPersisted($attachable);
        }

        $payload = [
            'name' => $name,
            'wildcard_enabled' => $wildcard,
            ...$options->toArray(),
        ];
        $resource = $this->client->createDomain($this->environmentId(), $payload);

        return DomainData::fromModel($this->persist($resource, $attachable, checked: true, fallbackAttributes: $payload));
    }

    /** @param array<string, mixed> $resource */
    private function persist(
        array $resource,
        ?Model $attachable = null,
        bool $checked = false,
        array $fallbackAttributes = [],
    ): LaravelCloudDomain {
        $remoteId = (string) ($resource['id'] ?? '');
        if ($remoteId === '') {
            throw new LaravelCloudException('Laravel Cloud returned a domain without an identifier.');
        }

        $attributes = [...$fallbackAttributes, ...(array) ($resource['attributes'] ?? [])];
        $environmentId = $this->environmentId();
        $domainClass = ModelResolver::class();

        /** @var LaravelCloudDomain $domain */
        $domain = DB::transaction(function () use ($domainClass, $environmentId, $remoteId, $resource, $attributes, $attachable, $checked) {
            /** @var LaravelCloudDomain $domain */
            $domain = $domainClass::query()->lockForUpdate()->firstOrNew([
                'environment_id' => $environmentId,
                'laravel_cloud_id' => $remoteId,
            ]);

            if ($attachable !== null && $domain->exists && $domain->attachable_type !== null
                && ($domain->attachable_type !== $attachable->getMorphClass() || (string) $domain->attachable_id !== (string) $attachable->getKey())) {
                throw new LaravelCloudConflictException('The remote domain is already attached to another owner.');
            }

            $values = [
                'name' => (string) ($attributes['name'] ?? $domain->name ?? ''),
                'remote_type' => isset($attributes['type']) ? (string) $attributes['type'] : $domain->remote_type,
                'wildcard_enabled' => (bool) ($attributes['wildcard_enabled'] ?? $domain->wildcard_enabled ?? false),
                'www_redirect' => $this->nullableString($attributes['www_redirect'] ?? $attributes['redirect'] ?? $domain->www_redirect),
                'allow_downtime' => isset($attributes['allow_downtime'])
                    ? (bool) $attributes['allow_downtime']
                    : (isset($attributes['downtime']) ? (bool) $attributes['downtime'] : $domain->allow_downtime),
                'cloudflare_strategy' => $this->nullableString($attributes['cloudflare_strategy'] ?? $domain->cloudflare_strategy),
                'verification_method' => $this->nullableString($attributes['verification_method'] ?? $domain->verification_method),
                'status' => $this->aggregateStatus($attributes),
                'hostname_status' => $this->nullableString($attributes['hostname_status'] ?? null),
                'ssl_status' => $this->nullableString($attributes['ssl_status'] ?? null),
                'origin_status' => $this->nullableString($attributes['origin_status'] ?? null),
                'variant_statuses' => $this->variantStatuses($attributes),
                'action_required' => $this->nullableString($attributes['action_required'] ?? null),
                'remote_payload' => $resource,
                'remote_created_at' => $this->date($attributes['created_at'] ?? null),
                'last_verified_at' => $this->date($attributes['last_verified_at'] ?? null),
                'last_synced_at' => now(),
                'last_checked_at' => $checked ? now() : $domain->last_checked_at,
                'last_error_code' => null,
                'last_error_message' => null,
            ];

            if ($attachable !== null) {
                $values['attachable_type'] = $attachable->getMorphClass();
                $values['attachable_id'] = (string) $attachable->getKey();
            }

            $domain->fill($values)->save();
            $domain->dnsRecords()->delete();
            $records = $this->dnsRecords($attributes);
            if ($records !== []) {
                $domain->dnsRecords()->createMany($records);
            }

            return $domain->refresh()->load('dnsRecords');
        });

        return $domain;
    }

    /** @param array<string, mixed> $attributes @return list<array<string, mixed>> */
    private function dnsRecords(array $attributes): array
    {
        $records = [];
        $sources = ['root' => $attributes];
        foreach (['wildcard', 'www'] as $scope) {
            if (isset($attributes[$scope]) && is_array($attributes[$scope])) {
                $sources[$scope] = $attributes[$scope];
            }
        }

        foreach ($sources as $scope => $source) {
            $requirements = $source['dns_records'] ?? null;
            if (! is_array($requirements)) {
                continue;
            }

            foreach (self::DNS_PURPOSES as $purpose) {
                if (! array_key_exists($purpose, $requirements) || $requirements[$purpose] === null || $requirements[$purpose] === []) {
                    continue;
                }

                $items = is_array($requirements[$purpose]) && array_is_list($requirements[$purpose])
                    ? $requirements[$purpose]
                    : [$requirements[$purpose]];

                foreach ($items as $position => $payload) {
                    $normalized = is_array($payload) ? $payload : [];
                    $records[] = [
                        'scope' => $scope,
                        'purpose' => $purpose,
                        'position' => $position,
                        'type' => $this->nullableString($normalized['type'] ?? null),
                        'name' => $this->nullableString($normalized['name'] ?? null),
                        'value' => $this->nullableString($normalized['value'] ?? $normalized['content'] ?? $normalized['target'] ?? (is_scalar($payload) ? $payload : null)),
                        'payload' => $payload,
                    ];
                }
            }
        }

        return $records;
    }

    /** @param array<string, mixed> $attributes */
    private function aggregateStatus(array $attributes): DomainStatus
    {
        $statusValues = $this->componentStatuses($attributes);
        $action = mb_strtolower((string) ($attributes['action_required'] ?? ''));

        if (str_contains($action, 'timed') || collect($statusValues)->contains(fn (string $status): bool => str_contains($status, 'timed') || str_contains($status, 'timeout'))) {
            return DomainStatus::TimedOut;
        }

        if ($this->isFullyConnected($attributes)) {
            return DomainStatus::Connected;
        }

        return DomainStatus::Pending;
    }

    /** @param array<string, mixed> $attributes @return list<string> */
    private function componentStatuses(array $attributes): array
    {
        $values = [];
        foreach ($this->statusVariants($attributes) as $variant) {
            foreach (['hostname_status', 'ssl_status', 'origin_status'] as $key) {
                if (isset($variant[$key]) && is_string($variant[$key]) && $variant[$key] !== '') {
                    $values[] = mb_strtolower($variant[$key]);
                }
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $attributes */
    private function isFullyConnected(array $attributes): bool
    {
        $expectedScopes = ['root'];
        if ((bool) ($attributes['wildcard_enabled'] ?? false)) {
            $expectedScopes[] = 'wildcard';
        }
        if (($attributes['www_redirect'] ?? $attributes['redirect'] ?? null) !== null) {
            $expectedScopes[] = 'www';
        }

        foreach ($expectedScopes as $scope) {
            $variant = $scope === 'root' ? $attributes : ($attributes[$scope] ?? null);
            if (! is_array($variant)) {
                return false;
            }

            foreach (['hostname_status', 'ssl_status', 'origin_status'] as $key) {
                $status = isset($variant[$key]) && is_string($variant[$key])
                    ? mb_strtolower($variant[$key])
                    : null;
                if (! in_array($status, ['active', 'connected'], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $attributes @return list<array<string, mixed>> */
    private function statusVariants(array $attributes): array
    {
        $variants = [$attributes];
        if ((bool) ($attributes['wildcard_enabled'] ?? false) && isset($attributes['wildcard']) && is_array($attributes['wildcard'])) {
            $variants[] = $attributes['wildcard'];
        }
        if (($attributes['www_redirect'] ?? $attributes['redirect'] ?? null) !== null && isset($attributes['www']) && is_array($attributes['www'])) {
            $variants[] = $attributes['www'];
        }

        return $variants;
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function variantStatuses(array $attributes): array
    {
        $statuses = [];
        foreach (['wildcard', 'www'] as $scope) {
            if (! isset($attributes[$scope]) || ! is_array($attributes[$scope])) {
                continue;
            }

            $statuses[$scope] = array_intersect_key($attributes[$scope], array_flip([
                'hostname_status', 'ssl_status', 'origin_status', 'action_required',
            ]));
        }

        return $statuses;
    }

    private function environmentId(): string
    {
        $environmentId = trim((string) config('laravel-cloud.api.environment_id'));
        if ($environmentId === '') {
            throw new LaravelCloudValidationException('Laravel Cloud environment ID is not configured.');
        }

        return $environmentId;
    }

    private function localDomain(string $id): LaravelCloudDomain
    {
        /** @var LaravelCloudDomain|null $domain */
        $domain = $this->domainQuery()
            ->where('environment_id', $this->environmentId())
            ->find($id);

        if ($domain === null) {
            throw new LaravelCloudNotFoundException("Local Laravel Cloud domain [$id] was not found.", 404);
        }

        return $domain;
    }

    private function domainQuery(): Builder
    {
        $class = ModelResolver::class();

        return $class::query();
    }

    private function assertPersisted(Model $model): void
    {
        if (! $model->exists || $model->getKey() === null) {
            throw new LaravelCloudValidationException('The attachable model must be persisted before attaching a domain.');
        }
    }

    private function recordError(LaravelCloudDomain $domain, Throwable $exception): void
    {
        $domain->update([
            'last_checked_at' => now(),
            'last_error_code' => (string) $exception->getCode(),
            'last_error_message' => $exception->getMessage(),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function date(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
