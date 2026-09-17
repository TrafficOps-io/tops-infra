# trafficops.io Laravel Cloud

Laravel 12 module for synchronizing and creating Laravel Cloud domains for one configured environment. It stores verification state and every DNS requirement returned by Laravel Cloud, and optionally associates domains with arbitrary Eloquent models.

Included in the MIT-licensed `trafficops/tops-infra` package. Install it with `composer require trafficops/tops-infra`.

## Configuration

```dotenv
LARAVEL_CLOUD_API_TOKEN=your-token
LARAVEL_CLOUD_ENVIRONMENT_ID=your-environment-id
```

The API token remains in configuration and is never persisted or serialized into jobs. Publish the full configuration when timeouts, the API base URL, migrations, model class, or check job need to be customized:

```sh
php artisan vendor:publish --tag=laravel-cloud-config
```

## Usage

```php
use TrafficOps\LaravelCloud\DTO\DomainOptions;
use TrafficOps\LaravelCloud\Enums\CloudflareStrategy;
use TrafficOps\LaravelCloud\Enums\VerificationMethod;
use TrafficOps\LaravelCloud\Enums\WwwRedirect;
use TrafficOps\LaravelCloud\Facades\LaravelCloud;

$domains = LaravelCloud::domains(); // Remote list, fully hydrated and persisted.

$exact = LaravelCloud::createExactDomain('app.example.com');
$wildcard = LaravelCloud::createWildcardDomain('*.example.com');

$custom = LaravelCloud::createExactDomain('example.com', options: new DomainOptions(
    wwwRedirect: WwwRedirect::WwwToRoot,
    allowDowntime: false,
    cloudflareStrategy: CloudflareStrategy::Dns,
    verificationMethod: VerificationMethod::PreVerification,
));

$fresh = LaravelCloud::domainStatus($exact->id); // Read current remote state.
$verified = LaravelCloud::verifyDomain($exact->id); // Trigger verification.
```

`createWildcardDomain` accepts an apex or `*.`-prefixed name and sends the apex with `wildcard_enabled=true`. Its default is uninterrupted pre-verification; exact domains default to real-time verification with downtime allowed.

## Model association

Add `HasLaravelCloudDomains` to an Eloquent model. Integer, UUID, and ULID keys are stored as strings.

```php
use TrafficOps\LaravelCloud\Traits\HasLaravelCloudDomains;

class Site extends Model
{
    use HasLaravelCloudDomains;
}

$domain = $site->createLaravelCloudExactDomain('customer.example.com');
$site->attachLaravelCloudDomain($unownedDomainId);
$site->laravelCloudDomains;
```

A domain may be unowned or associated with one model. Attaching it to a different model is rejected. Override the persisted domain class with `laravel-cloud.models.domain`; the custom class must extend `LaravelCloudDomain`.

DNS requirements are stored as normalized rows by `root`, `wildcard`, or `www` scope and by their API purpose. Each row also retains the complete original payload, including scalar requirements that cannot be normalized further.

## Background verification

Schedule the dispatcher at the cadence appropriate for the application:

```php
Schedule::command('laravel-cloud:dispatch-domain-checks')->everyFiveMinutes();
```

The command queues one configured job per pending domain. Connected, timed-out, and remotely detached domains are not dispatched. Extend `AbstractCheckLaravelCloudDomainJob` and set `laravel-cloud.check_job` to customize the concrete queued class.
