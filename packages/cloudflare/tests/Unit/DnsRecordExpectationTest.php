<?php

namespace TrafficOps\Cloudflare\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;

class DnsRecordExpectationTest extends TestCase
{
    public function test_equivalent_ipv6_formats_match_existing_records_and_share_a_signature(): void
    {
        $compressed = new DnsRecordExpectation('AAAA', 'example.com', '2001:db8::42', proxied: false);
        $expanded = new DnsRecordExpectation('AAAA', 'example.com', ' 2001:0DB8:0000:0000:0000:0000:0000:0042 ', proxied: false);

        $this->assertSame('2001:db8::42', $expanded->content);
        $this->assertSame($compressed->signature(), $expanded->signature());
        $this->assertTrue($compressed->matches([
            'type' => 'AAAA', 'name' => 'example.com',
            'content' => '2001:0db8:0000:0000:0000:0000:0000:0042', 'proxied' => false,
        ]));
        $this->assertTrue($expanded->matches($compressed->toArray()));
        $this->assertFalse($expanded->matches([
            'type' => 'AAAA', 'name' => 'example.com', 'content' => '2001:db8::43', 'proxied' => false,
        ]));
    }

    public function test_ipv4_records_keep_their_address_and_detect_drift(): void
    {
        $record = new DnsRecordExpectation('A', 'example.com', ' 203.0.113.42 ');

        $this->assertSame('203.0.113.42', $record->content);
        $this->assertTrue($record->matches(['type' => 'A', 'name' => 'example.com', 'content' => '203.0.113.42']));
        $this->assertFalse($record->matches(['type' => 'A', 'name' => 'example.com', 'content' => '203.0.113.43']));
    }

    public function test_invalid_address_content_retains_the_existing_trim_only_behavior(): void
    {
        foreach (['A', 'AAAA'] as $type) {
            $record = new DnsRecordExpectation($type, 'example.com', ' invalid-address ');

            $this->assertSame('invalid-address', $record->content);
            $this->assertTrue($record->matches(['type' => $type, 'name' => 'example.com', 'content' => 'invalid-address']));
            $this->assertFalse($record->matches(['type' => $type, 'name' => 'example.com', 'content' => 'another-invalid-address']));
        }
    }
}
