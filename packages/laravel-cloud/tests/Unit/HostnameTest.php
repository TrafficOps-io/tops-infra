<?php

namespace TrafficOps\LaravelCloud\Tests\Unit;

use TrafficOps\LaravelCloud\Exceptions\LaravelCloudValidationException;
use TrafficOps\LaravelCloud\Support\Hostname;
use TrafficOps\LaravelCloud\Tests\TestCase;

final class HostnameTest extends TestCase
{
    public function test_normalizes_exact_and_wildcard_hosts(): void
    {
        $this->assertSame('example.com', Hostname::exact('EXAMPLE.com.'));
        $this->assertSame('example.com', Hostname::wildcardBase('*.EXAMPLE.com.'));
    }

    public function test_rejects_invalid_wildcards(): void
    {
        $this->expectException(LaravelCloudValidationException::class);

        Hostname::wildcardBase('foo.*.example.com');
    }
}
