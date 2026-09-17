<?php

namespace TrafficOps\Cloudflare\Tests\Fixtures;

use TrafficOps\Cloudflare\Contracts\CloudflareClientContract;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;

final class FakeCloudflareClient implements CloudflareClientContract
{
    public bool $valid = true;

    /** @var list<array<string, mixed>> */
    public array $zones = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $records = [];

    /** @var list<string> */
    public array $deleted = [];

    public function verifyToken(string $token): array
    {
        if (! $this->valid) {
            throw new CloudflareAuthenticationException('Invalid token.');
        }

        return ['id' => 'token-id', 'status' => 'active'];
    }

    public function listZones(string $token): array
    {
        return $this->zones;
    }

    public function listDnsRecords(string $token, string $zoneId): array
    {
        return $this->records[$zoneId] ?? [];
    }

    public function createDnsRecord(string $token, string $zoneId, array $record): array
    {
        $created = ['id' => str_pad((string) (count($this->records[$zoneId] ?? []) + 1), 32, '0', STR_PAD_LEFT), ...$record];
        $this->records[$zoneId][] = $created;

        return $created;
    }

    public function updateDnsRecord(string $token, string $zoneId, string $recordId, array $record): array
    {
        foreach ($this->records[$zoneId] ?? [] as $index => $existing) {
            if (($existing['id'] ?? null) === $recordId) {
                return $this->records[$zoneId][$index] = ['id' => $recordId, ...$record];
            }
        }

        return [];
    }

    public function deleteDnsRecord(string $token, string $zoneId, string $recordId): void
    {
        $this->deleted[] = $recordId;
        $this->records[$zoneId] = array_values(array_filter(
            $this->records[$zoneId] ?? [],
            fn (array $record) => ($record['id'] ?? null) !== $recordId,
        ));
    }
}
