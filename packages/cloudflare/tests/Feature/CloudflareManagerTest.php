<?php

namespace TrafficOps\Cloudflare\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use TrafficOps\Cloudflare\Contracts\CloudflareClientContract;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\DTO\DomainDefinition;
use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\Enums\DomainStatus;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Enums\RecordOwnership;
use TrafficOps\Cloudflare\Events\CloudflareDnsDriftDetected;
use TrafficOps\Cloudflare\Events\CloudflareDomainStatusChanged;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflareConflictException;
use TrafficOps\Cloudflare\Jobs\CheckCloudflareIntegrationJob;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;
use TrafficOps\Cloudflare\Models\CloudflareZone;
use TrafficOps\Cloudflare\Tests\Fixtures\CustomIntegration;
use TrafficOps\Cloudflare\Tests\Fixtures\FakeCloudflareClient;
use TrafficOps\Cloudflare\Tests\Fixtures\FakeDnsResolver;
use TrafficOps\Cloudflare\Tests\Fixtures\TestOwner;
use TrafficOps\Cloudflare\Tests\TestCase;

final class CloudflareManagerTest extends TestCase
{
    private FakeCloudflareClient $client;

    private CloudflareManagerContract $manager;

    private FakeDnsResolver $dns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeCloudflareClient;
        $this->client->zones = [
            $this->zone('zone-one', 'account-one', 'example.com'),
            $this->zone('zone-two', 'account-two', 'example.net'),
        ];
        $this->app->instance(CloudflareClientContract::class, $this->client);
        $this->dns = new FakeDnsResolver;
        $this->app->instance(PublicDnsResolverContract::class, $this->dns);
        $this->app->forgetInstance(CloudflareManagerContract::class);
        $this->manager = $this->app->make(CloudflareManagerContract::class);
    }

    public function test_connect_encrypts_token_and_discovers_multiple_accounts(): void
    {
        $owner = TestOwner::query()->create(['name' => 'Owner']);
        $data = $owner->connectCloudflare('super-secret-token', 'Production');
        $integration = CloudflareIntegration::query()->findOrFail($data->id);

        $this->assertCount(2, $integration->accounts);
        $this->assertCount(2, $this->manager->accounts($owner, $data->id));
        $this->assertCount(2, $this->manager->zones($owner, $data->id));
        $this->assertSame('super-secret-token', $integration->api_token);
        $this->assertNotSame('super-secret-token', DB::table('cloudflare_integrations')->value('api_token'));
        $this->assertArrayNotHasKey('api_token', $integration->toArray());
        $this->assertCount(1, $owner->cloudflareIntegrations);
        $this->assertCount(1, TestOwner::query()->with('cloudflareIntegrations')->findOrFail($owner->getKey())->cloudflareIntegrations);
    }

    public function test_invalid_token_is_not_persisted(): void
    {
        $this->client->valid = false;
        $owner = TestOwner::query()->create(['name' => 'Owner']);

        try {
            $owner->connectCloudflare('bad-token');
            $this->fail('Expected authentication exception.');
        } catch (CloudflareAuthenticationException) {
            $this->assertDatabaseCount('cloudflare_integrations', 0);
        }
    }

    public function test_reconcile_creates_and_then_checks_a_managed_wildcard_record(): void
    {
        [$owner, $integration, $zone] = $this->connectedOwner();
        $domain = $this->manager->attachDomain($owner, $integration->id, $zone->getKey(), new DomainDefinition('*.example.com', [
            new DnsRecordExpectation('CNAME', '*.example.com', 'origin.example.net', proxied: true),
        ]));

        $provision = $this->manager->reconcileDomain($owner, $domain->id);
        $check = $this->manager->checkIntegration($owner, $integration->id);
        $record = $zone->domains()->firstOrFail()->records()->firstOrFail();

        $this->assertSame(1, $provision->created);
        $this->assertSame(RecordOwnership::Managed, $record->ownership);
        $this->assertSame(DomainStatus::Active, $check->domains[0]->status);
    }

    public function test_matching_external_record_is_adopted_and_never_deleted(): void
    {
        [$owner, $integration, $zone] = $this->connectedOwner();
        $this->client->records[$zone->cloudflare_id] = [[
            'id' => str_repeat('e', 32),
            'type' => 'CNAME',
            'name' => 'app.example.com',
            'content' => 'origin.example.net',
            'ttl' => 1,
            'proxied' => true,
        ]];
        $domain = $this->manager->attachDomain($owner, $integration->id, $zone->getKey(), new DomainDefinition('app.example.com', [
            new DnsRecordExpectation('CNAME', 'app.example.com', 'origin.example.net', proxied: true),
        ]));

        $result = $this->manager->reconcileDomain($owner, $domain->id);
        $secondResult = $this->manager->reconcileDomain($owner, $domain->id);
        $this->manager->removeDomain($owner, $domain->id, true);

        $this->assertSame(1, $result->adopted);
        $this->assertSame(0, $secondResult->adopted);
        $this->assertSame([], $this->client->deleted);
    }

    public function test_rejects_cross_owner_wildcard_overlap(): void
    {
        [$owner, $integration, $zone] = $this->connectedOwner();
        $this->manager->attachDomain($owner, $integration->id, $zone->getKey(), new DomainDefinition('*.example.com', [
            new DnsRecordExpectation('CNAME', '*.example.com', 'origin.example.net'),
        ]));

        $other = TestOwner::query()->create(['name' => 'Other']);
        $otherIntegration = $other->connectCloudflare('another-token');
        $otherZone = $other->cloudflareIntegrations()->firstOrFail()->accounts()->firstOrFail()->zones()->where('name', 'example.com')->firstOrFail();

        $this->expectException(CloudflareConflictException::class);
        $this->manager->attachDomain($other, $otherIntegration->id, $otherZone->getKey(), new DomainDefinition('app.example.com', [
            new DnsRecordExpectation('CNAME', 'app.example.com', 'origin.example.net'),
        ]));
    }

    public function test_explicit_cleanup_deletes_only_managed_records(): void
    {
        [$owner, $integration, $zone] = $this->connectedOwner();
        $domain = $this->manager->attachDomain($owner, $integration->id, $zone->getKey(), new DomainDefinition('app.example.com', [
            new DnsRecordExpectation('CNAME', 'app.example.com', 'origin.example.net'),
        ]));
        $this->manager->reconcileDomain($owner, $domain->id);
        $remoteId = $zone->domains()->firstOrFail()->records()->firstOrFail()->cloudflare_record_id;

        $this->manager->removeDomain($owner, $domain->id, true);

        $this->assertSame([$remoteId], $this->client->deleted);
    }

    public function test_reconcile_updates_a_managed_record_in_place(): void
    {
        [$owner, $integration, $zone] = $this->connectedOwner();
        $domain = $this->manager->attachDomain($owner, $integration->id, $zone->getKey(), new DomainDefinition('app.example.com', [
            new DnsRecordExpectation('CNAME', 'app.example.com', 'old-origin.example.net'),
        ]));
        $this->manager->reconcileDomain($owner, $domain->id);
        $originalId = $zone->domains()->firstOrFail()->records()->firstOrFail()->cloudflare_record_id;
        $this->manager->replaceDomainExpectations($owner, $domain->id, [
            new DnsRecordExpectation('CNAME', 'app.example.com', 'new-origin.example.net'),
        ]);

        $result = $this->manager->reconcileDomain($owner, $domain->id);
        $record = $zone->domains()->firstOrFail()->records()->where('desired', true)->firstOrFail();

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->created);
        $this->assertSame($originalId, $record->cloudflare_record_id);
        $this->assertSame('new-origin.example.net', $record->content);
    }

    public function test_configured_integration_model_is_used(): void
    {
        config(['cloudflare.models.integration' => CustomIntegration::class]);
        $owner = TestOwner::query()->create(['name' => 'Owner']);

        $data = $owner->connectCloudflare('custom-model-token');

        $this->assertInstanceOf(CustomIntegration::class, CustomIntegration::query()->findOrFail($data->id));
        $this->assertInstanceOf(CustomIntegration::class, $owner->cloudflareIntegrations()->firstOrFail());
    }

    public function test_dispatch_command_queues_ids_without_tokens(): void
    {
        Queue::fake();
        [$owner, $integration] = $this->connectedOwner();

        $this->artisan('cloudflare:dispatch-checks')->assertSuccessful();

        Queue::assertPushed(CheckCloudflareIntegrationJob::class, fn ($job) => $job->integrationId === $integration->id);
        $this->assertStringNotContainsString('valid-token', serialize(new CheckCloudflareIntegrationJob($integration->id)));
        $this->assertSame($integration->id, $owner->cloudflareIntegrations()->firstOrFail()->getKey());
    }

    public function test_public_resolver_failure_does_not_report_dns_drift(): void
    {
        [$owner, $integration, $zone] = $this->connectedOwner();
        $domain = $this->manager->attachDomain($owner, $integration->id, $zone->getKey(), new DomainDefinition('app.example.com', [
            new DnsRecordExpectation('CNAME', 'app.example.com', 'origin.example.net'),
        ]));
        $this->manager->reconcileDomain($owner, $domain->id);
        $this->dns->fails = true;

        $result = $this->manager->checkIntegration($owner, $integration->id);

        $this->assertSame(DomainStatus::Unreachable, $result->domains[0]->status);
        $this->assertSame(IntegrationStatus::Degraded, $result->status);
    }

    public function test_sync_marks_missing_accounts_inaccessible_without_deleting_them(): void
    {
        [$owner, $integration] = $this->connectedOwner();
        $this->client->zones = [$this->zone('zone-one', 'account-one', 'example.com')];

        $this->manager->sync($owner, $integration->id);
        $accounts = $this->manager->accounts($owner, $integration->id);

        $this->assertCount(2, $accounts);
        $this->assertSame(['active', 'inaccessible'], array_values(array_unique(array_column($accounts, 'status'))));
    }

    public function test_status_events_are_only_emitted_on_transitions(): void
    {
        [$owner, $integration, $zone] = $this->connectedOwner();
        $this->manager->attachDomain($owner, $integration->id, $zone->getKey(), new DomainDefinition('app.example.com', [
            new DnsRecordExpectation('CNAME', 'app.example.com', 'origin.example.net'),
        ]));
        Event::fake([CloudflareDomainStatusChanged::class, CloudflareDnsDriftDetected::class]);
        $this->app->forgetInstance(CloudflareManagerContract::class);
        $this->manager = $this->app->make(CloudflareManagerContract::class);

        $this->manager->checkIntegration($owner, $integration->id);
        $this->manager->checkIntegration($owner, $integration->id);

        Event::assertDispatchedTimes(CloudflareDomainStatusChanged::class, 1);
        Event::assertDispatchedTimes(CloudflareDnsDriftDetected::class, 1);
    }

    /** @return array{TestOwner, IntegrationData, CloudflareZone} */
    private function connectedOwner(): array
    {
        $owner = TestOwner::query()->create(['name' => 'Owner']);
        $integration = $owner->connectCloudflare('valid-token');
        $zone = $owner->cloudflareIntegrations()->firstOrFail()->accounts()->firstOrFail()->zones()->where('name', 'example.com')->firstOrFail();

        return [$owner, $integration, $zone];
    }

    /** @return array<string, mixed> */
    private function zone(string $zoneId, string $accountId, string $name): array
    {
        return [
            'id' => str_pad($zoneId, 32, '0'),
            'name' => $name,
            'status' => 'active',
            'type' => 'full',
            'paused' => false,
            'account' => ['id' => str_pad($accountId, 32, '0'), 'name' => $accountId],
        ];
    }
}
