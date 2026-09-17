<?php

namespace TrafficOps\Cloudflare\Tests\Fixtures;

use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\DTO\DnsLookupResult;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;

final class FakeDnsResolver implements PublicDnsResolverContract
{
    /** @var list<string> */
    public array $answers = ['203.0.113.10'];

    public bool $fails = false;

    public function resolve(string $name, string $type): DnsLookupResult
    {
        if ($this->fails) {
            throw new CloudflareTransportException('Resolver unavailable.');
        }

        return new DnsLookupResult($name, $type, $this->answers);
    }
}
