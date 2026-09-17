<?php

namespace TrafficOps\LaravelCloud\Contracts;

interface LaravelCloudClientContract
{
    /** @return list<array<string, mixed>> */
    public function listDomains(string $environmentId): array;

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public function createDomain(string $environmentId, array $attributes): array;

    /** @return array<string, mixed> */
    public function getDomain(string $domainId): array;

    /** @return array<string, mixed> */
    public function verifyDomain(string $domainId): array;
}
