<?php

namespace TrafficOps\Cloudflare\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\Dns\PublicDnsRecordChecker;
use TrafficOps\Cloudflare\DTO\DnsLookupResult;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\Support\Hostname;

class PublicDnsRecordCheckerTest extends TestCase
{
    public function test_flattened_apex_uses_addresses_as_evidence(): void
    {
        $checker = $this->checker(['example.com:A' => ['203.0.113.10'], 'origin.test:A' => ['203.0.113.10']]);
        $result = $checker->check(new DnsRecordExpectation('CNAME', 'example.com', 'origin.test', proxied: false), 'id', true);
        $this->assertSame('matched', $result['status']);
        $this->assertSame('flattened_addresses', $result['evidence']);
        $this->assertSame(['203.0.113.10'], $result['answers']['addresses']['A']);
    }

    public function test_unproven_flattening_is_indeterminate_not_matched(): void
    {
        $result = $this->checker(['example.com:A' => ['203.0.113.10'], 'origin.test:A' => ['203.0.113.20']])
            ->check(new DnsRecordExpectation('CNAME', 'example.com', 'origin.test', proxied: false), 'id', true);
        $this->assertSame('indeterminate', $result['status']);
    }

    public function test_direct_wrong_cname_is_not_hidden_by_flattening(): void
    {
        $result = $this->checker(['example.com:CNAME' => ['wrong.test']])->check(new DnsRecordExpectation('CNAME', 'example.com', 'origin.test'), 'id', true);
        $this->assertSame('mismatched', $result['status']);
    }

    public function test_wildcard_uses_a_probe_and_normalizes_cname_case_and_dot(): void
    {
        $name = Hostname::wildcardProbe('*.example.com', 'id');
        $result = $this->checker([$name.':CNAME' => ['ORIGIN.TEST.']])->check(new DnsRecordExpectation('CNAME', '*.example.com', 'origin.test'), 'id');
        $this->assertSame($name, $result['name']);
        $this->assertSame('matched', $result['status']);
    }

    public function test_ipv6_equivalent_addresses_match(): void
    {
        $result = $this->checker(['example.com:AAAA' => ['2001:0db8:0000:0000:0000:0000:0000:0001']])->check(new DnsRecordExpectation('AAAA', 'example.com', '2001:db8::1'), 'id');
        $this->assertSame('matched', $result['status']);
    }

    public function test_txt_values_remain_case_sensitive(): void
    {
        $result = $this->checker(['_check.example.com:TXT' => ['TOKEN']])->check(new DnsRecordExpectation('TXT', '_check.example.com', 'token'), 'id');
        $this->assertSame('mismatched', $result['status']);
    }

    public function test_absent_apex_is_missing_even_when_flattening_is_allowed(): void
    {
        $result = $this->checker(['origin.test:A' => ['203.0.113.10']])->check(new DnsRecordExpectation('CNAME', 'example.com', 'origin.test'), 'id', true);
        $this->assertSame('missing', $result['status']);
    }

    private function checker(array $answers): PublicDnsRecordChecker
    {
        return new PublicDnsRecordChecker(new class($answers) implements PublicDnsResolverContract
        {
            public function __construct(private array $answers) {}

            public function resolve(string $name, string $type): DnsLookupResult
            {
                return new DnsLookupResult($name, $type, $this->answers[$name.':'.$type] ?? []);
            }
        });
    }
}
