<?php

use TrafficOps\Cloudflare\Jobs\CheckCloudflareIntegrationJob;
use TrafficOps\Cloudflare\Models\CloudflareAccount;
use TrafficOps\Cloudflare\Models\CloudflareDomain;
use TrafficOps\Cloudflare\Models\CloudflareDomainRecord;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;
use TrafficOps\Cloudflare\Models\CloudflareZone;

return [
    'api' => [
        'base_url' => env('CLOUDFLARE_API_BASE_URL', 'https://api.cloudflare.com/client/v4'),
        'connect_timeout' => 5,
        'timeout' => 15,
        'retries' => 2,
    ],

    'dns' => [
        'resolver_url' => env('CLOUDFLARE_DNS_RESOLVER_URL', 'https://cloudflare-dns.com/dns-query'),
    ],

    'models' => [
        'integration' => CloudflareIntegration::class,
        'account' => CloudflareAccount::class,
        'zone' => CloudflareZone::class,
        'domain' => CloudflareDomain::class,
        'record' => CloudflareDomainRecord::class,
    ],

    'migrations' => true,
    'claim_lock_store' => null,
    'claim_lock_seconds' => 10,
    'check_job' => CheckCloudflareIntegrationJob::class,
];
