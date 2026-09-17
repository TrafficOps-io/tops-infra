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

    public function test_detects_exact_and_wildcard_overlap(): void
    {
        $this->assertTrue(Hostname::overlaps('*.example.com', 'app.example.com'));
        $this->assertTrue(Hostname::overlaps('*.example.com', '*.sub.example.com'));
        $this->assertFalse(Hostname::overlaps('*.example.com', 'example.com'));
        $this->assertFalse(Hostname::overlaps('*.example.com', 'example.net'));
    }
}
