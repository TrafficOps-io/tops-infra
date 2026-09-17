<?php

namespace TrafficOps\LaravelCloud\Tests\Fixtures;

use TrafficOps\LaravelCloud\Contracts\LaravelCloudClientContract;

final class FakeLaravelCloudClient implements LaravelCloudClientContract
{
    /** @var array<string, array<string, mixed>> */
    public array $domains = [];

    /** @var array<string, array<string, mixed>> */
    public array $verificationResults = [];

    /** @var list<array<string, mixed>> */
    public array $createdPayloads = [];

    /** @var array<string, mixed>|null */
    public ?array $nextCreateResult = null;

    public function listDomains(string $environmentId): array
    {
        return array_map(fn (array $domain): array => ['id' => $domain['id']], array_values($this->domains));
    }

    public function createDomain(string $environmentId, array $attributes): array
    {
        $this->createdPayloads[] = ['environment_id' => $environmentId, ...$attributes];
        $resource = $this->nextCreateResult ?? [
            'id' => 'remote-'.(count($this->domains) + 1),
            'type' => 'domains',
            'attributes' => [
                ...$attributes,
                'hostname_status' => 'pending',
                'ssl_status' => 'pending',
                'origin_status' => 'pending',
                'dns_records' => [],
            ],
        ];
        $this->domains[$resource['id']] = $resource;

        return $resource;
    }

    public function getDomain(string $domainId): array
    {
        return $this->domains[$domainId];
    }

    public function verifyDomain(string $domainId): array
    {
        $resource = $this->verificationResults[$domainId] ?? $this->domains[$domainId];
        $this->domains[$domainId] = $resource;

        return $resource;
    }
}
