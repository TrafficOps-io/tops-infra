<?php

namespace TrafficOps\Cloudflare\Contracts;

interface CloudflareClientContract
{
    /** @return array<string, mixed> */
    public function verifyToken(string $token): array;

    /** @return list<array<string, mixed>> */
    public function listZones(string $token): array;

    /** @return list<array<string, mixed>> */
    public function listDnsRecords(string $token, string $zoneId): array;

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function createDnsRecord(string $token, string $zoneId, array $record): array;

    /** @param array<string, mixed> $record @return array<string, mixed> */
    public function updateDnsRecord(string $token, string $zoneId, string $recordId, array $record): array;

    public function deleteDnsRecord(string $token, string $zoneId, string $recordId): void;
}
