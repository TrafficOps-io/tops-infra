<?php

namespace TrafficOps\Cloudflare\Contracts;

use TrafficOps\Cloudflare\DTO\DnsLookupResult;

interface PublicDnsResolverContract
{
    public function resolve(string $name, string $type): DnsLookupResult;
}
