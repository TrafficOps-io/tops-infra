<?php

namespace TrafficOps\Cloudflare\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;
use TrafficOps\Cloudflare\Support\Hostname;

final class HostnameTest extends TestCase
{
    public function test_normalizes_idn_and_wildcard_hostnames(): void
    {
        $this->assertSame('xn--e1afmkfd.xn--p1ai', Hostname::normalize('ПРИМЕР.РФ.'));
        $this->assertSame('*.example.com', Hostname::normalize('*.Example.COM', allowWildcard: true));
    }

    #[DataProvider('invalidHostnames')]
    public function test_rejects_invalid_hostnames(string $hostname): void
    {
        $this->expectException(CloudflareValidationException::class);
        Hostname::normalize($hostname, allowWildcard: true);
    }

    public static function invalidHostnames(): array
    {
        return [['example'], ['foo.*.example.com'], ['*.*.example.com'], ['-bad.example.com']];
    }

    public function test_exact_claim_covers_itself_and_names_beneath_it(): void
    {
        $this->assertTrue(Hostname::covers('app.example.com', 'app.example.com'));
        $this->assertTrue(Hostname::covers('app.example.com', '_acme-challenge.app.example.com'));
        $this->assertTrue(Hostname::covers('app.example.com', '*.app.example.com'));
        $this->assertFalse(Hostname::covers('app.example.com', 'example.com'));
        $this->assertFalse(Hostname::covers('app.example.com', 'other.example.com'));
        $this->assertFalse(Hostname::covers('app.example.com', 'notapp.example.com'));
    }

    public function test_wildcard_claim_covers_names_beneath_its_base_but_not_the_apex(): void
    {
        $this->assertTrue(Hostname::covers('*.example.com', '*.example.com'));
        $this->assertTrue(Hostname::covers('*.example.com', 'app.example.com'));
        $this->assertTrue(Hostname::covers('*.example.com', '_acme-challenge.example.com'));
        $this->assertTrue(Hostname::covers('*.example.com', Hostname::wildcardProbe('*.example.com', 'seed')));
        $this->assertTrue(Hostname::covers('*.example.com', '*.sub.example.com'));
        $this->assertFalse(Hostname::covers('*.example.com', 'example.com'));
        $this->assertFalse(Hostname::covers('*.example.com', 'example.net'));
        $this->assertFalse(Hostname::covers('*.example.com', 'notexample.com'));
    }

    public function test_detects_exact_and_wildcard_overlap(): void
    {
        $this->assertTrue(Hostname::overlaps('*.example.com', 'app.example.com'));
        $this->assertTrue(Hostname::overlaps('*.example.com', '*.sub.example.com'));
        $this->assertFalse(Hostname::overlaps('*.example.com', 'example.com'));
        $this->assertFalse(Hostname::overlaps('*.example.com', 'example.net'));
    }
}
