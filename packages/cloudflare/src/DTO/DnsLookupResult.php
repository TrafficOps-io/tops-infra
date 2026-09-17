<?php

namespace TrafficOps\Cloudflare\DTO;

final readonly class DnsLookupResult
{
    /** @param list<string> $answers */
    public function __construct(public string $name, public string $type, public array $answers) {}
}
