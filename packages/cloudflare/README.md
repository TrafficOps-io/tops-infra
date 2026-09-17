# trafficops.io Cloudflare

Laravel 12 module for connecting customer-owned Cloudflare API tokens, discovering accounts and zones, binding exact or wildcard domains, provisioning expected DNS records, and monitoring drift.

This headless module is included in the MIT-licensed `trafficops/tops-infra` package.

## Install in an application

```sh
composer require trafficops/tops-infra
```

Add `HasCloudflareIntegrations` to any Eloquent owner model. The owner key is stored as a string, so integer, UUID, and ULID model keys are supported.

```php
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\DTO\DomainDefinition;
use TrafficOps\Cloudflare\Facades\Cloudflare;
use TrafficOps\Cloudflare\Traits\HasCloudflareIntegrations;

$integration = $owner->connectCloudflare($token, 'Customer production');
$zone = Cloudflare::zones($owner, $integration->id)[0];

$domain = Cloudflare::attachDomain(
    $owner,
    $integration->id,
    $zone->id,
    new DomainDefinition('*.example.com', [
        new DnsRecordExpectation('CNAME', '*.example.com', 'origin.platform.test', proxied: true),
    ]),
);

Cloudflare::reconcileDomain($owner, $domain->id); // explicit write
Cloudflare::checkIntegration($owner, $integration->id); // read-only
```

Tokens should have `Zone Read` and `DNS Read`; explicit provisioning also needs `DNS Write`. Existing matching records are adopted but never modified or deleted. Only records created by this package are eligible for explicit cleanup.

Schedule checks at the cadence appropriate for the host application:

```php
Schedule::command('cloudflare:dispatch-checks')->hourly();
```

Override `cloudflare.check_job`, model classes, the public DNS resolver, or the Cloudflare client through configuration/container bindings when an application needs custom behavior.

`Dns\PublicDnsRecordChecker` provides a read-only comparison using the configured `PublicDnsResolverContract`. It returns status, queried name, observed answers and evidence, and can be reused for manual DNS integrations. With `allowFlattening: true`, absent CNAME answers are compared through A/AAAA addresses: no addresses are missing, matching addresses provide flattening evidence, and ambiguous addresses remain indeterminate. The package uses this for apex checks; it does not infer a direct CNAME match from unrelated IP addresses.
