<?php

namespace TrafficOps\Cloudflare\DTO;

use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;
use TrafficOps\Cloudflare\Support\Hostname;

final readonly class DomainDefinition
{
    public string $hostname;

    /** @var list<DnsRecordExpectation> */
    public array $records;

    /** @param list<DnsRecordExpectation> $records */
    public function __construct(string $hostname, array $records)
    {
        $this->hostname = Hostname::normalize($hostname, allowWildcard: true);

        if ($records === []) {
            throw new CloudflareValidationException('A domain needs at least one expected DNS record.');
        }

        foreach ($records as $record) {
            if (! $record instanceof DnsRecordExpectation) {
                throw new CloudflareValidationException('Domain records must be DnsRecordExpectation instances.');
            }
        }

        $this->records = array_values($records);
    }

    public function isWildcard(): bool
    {
        return str_starts_with($this->hostname, '*.');
    }
}
