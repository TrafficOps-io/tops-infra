<?php

namespace TrafficOps\LaravelCloud\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudClientContract;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudManagerContract;
use TrafficOps\LaravelCloud\Enums\DomainStatus;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudConflictException;
use TrafficOps\LaravelCloud\Jobs\CheckLaravelCloudDomainJob;
use TrafficOps\LaravelCloud\Models\LaravelCloudDomain;
use TrafficOps\LaravelCloud\Tests\Fixtures\CustomDomain;
use TrafficOps\LaravelCloud\Tests\Fixtures\FakeLaravelCloudClient;
use TrafficOps\LaravelCloud\Tests\Fixtures\TestOwner;
use TrafficOps\LaravelCloud\Tests\Fixtures\TestStringOwner;
use TrafficOps\LaravelCloud\Tests\TestCase;

final class LaravelCloudManagerTest extends TestCase
{
    private FakeLaravelCloudClient $client;

    private LaravelCloudManagerContract $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeLaravelCloudClient;
        $this->app->instance(LaravelCloudClientContract::class, $this->client);
        $this->app->forgetInstance(LaravelCloudManagerContract::class);
        $this->manager = $this->app->make(LaravelCloudManagerContract::class);
    }

    public function test_exact_domain_is_created_for_an_owner_and_all_dns_payloads_are_preserved(): void
    {
        $owner = TestOwner::query()->create(['name' => 'Owner']);
        $this->client->nextCreateResult = $this->resource('remote-exact', 'example.com', [
            'dns_records' => [
                'ssl' => [['type' => 'CNAME', 'name' => '_acme.example.com', 'value' => 'ssl.target.test']],
                'pre_verification' => 'ownership-token',
                'origin' => ['type' => 'A', 'name' => 'example.com', 'content' => '192.0.2.1'],
            ],
        ]);

        $data = $owner->createLaravelCloudExactDomain('EXAMPLE.com.');
        $domain = LaravelCloudDomain::query()->findOrFail($data->id);

        $this->assertSame('example.com', $data->name);
        $this->assertSame($owner->getMorphClass(), $domain->attachable_type);
        $this->assertSame((string) $owner->getKey(), $domain->attachable_id);
        $this->assertCount(3, $data->dnsRecords);
        $this->assertSame('ownership-token', collect($data->dnsRecords)->firstWhere('purpose', 'pre_verification')->payload);
        $this->assertSame('ownership-token', collect($data->dnsRecords)->firstWhere('purpose', 'pre_verification')->value);
        $this->assertSame('environment-one', $this->client->createdPayloads[0]['environment_id']);
        $this->assertTrue($this->client->createdPayloads[0]['allow_downtime']);
        $this->assertSame('real_time', $this->client->createdPayloads[0]['verification_method']);
        $this->assertCount(1, $owner->laravelCloudDomains);
    }

    public function test_wildcard_defaults_and_variant_statuses_produce_connected_domain(): void
    {
        $this->client->nextCreateResult = $this->resource('remote-wildcard', 'example.com', [
            'wildcard_enabled' => true,
            'hostname_status' => 'active',
            'ssl_status' => 'active',
            'origin_status' => 'active',
            'wildcard' => [
                'hostname_status' => 'active',
                'ssl_status' => 'active',
                'origin_status' => 'active',
                'dns_records' => ['dcv' => ['type' => 'CNAME', 'name' => '_acme-challenge.example.com', 'value' => 'dcv.test']],
            ],
            'www' => [
                'hostname_status' => 'active',
                'ssl_status' => 'active',
                'origin_status' => 'active',
                'dns_records' => ['origin_cname' => 'www-origin.test'],
            ],
        ]);

        $data = $this->manager->createWildcardDomain('*.example.com');

        $this->assertSame(DomainStatus::Connected, $data->status);
        $this->assertArrayHasKey('wildcard', $data->variantStatuses);
        $this->assertSame(['wildcard', 'www'], collect($data->dnsRecords)->pluck('scope')->sort()->values()->all());
        $this->assertFalse($this->client->createdPayloads[0]['allow_downtime']);
        $this->assertSame('pre_verification', $this->client->createdPayloads[0]['verification_method']);
        $this->assertTrue($this->client->createdPayloads[0]['wildcard_enabled']);
    }

    public function test_sync_hydrates_details_marks_missing_domains_detached_and_revives_them(): void
    {
        $this->client->domains['remote-one'] = $this->resource('remote-one', 'one.example.com');
        $this->manager->domains();
        $domain = LaravelCloudDomain::query()->firstOrFail();

        $this->client->domains = [];
        $this->assertSame([], $this->manager->domains());
        $this->assertSame(DomainStatus::Detached, $domain->refresh()->status);

        $this->client->domains['remote-one'] = $this->resource('remote-one', 'one.example.com', [
            'hostname_status' => 'active', 'ssl_status' => 'active', 'origin_status' => 'active',
        ]);
        $this->manager->domains();

        $this->assertSame(DomainStatus::Connected, $domain->refresh()->status);
        $this->assertDatabaseCount('laravel_cloud_domains', 1);
    }

    public function test_unowned_domain_can_be_attached_once_but_not_claimed_by_another_owner(): void
    {
        $this->client->nextCreateResult = $this->resource('remote-one', 'one.example.com');
        $domain = $this->manager->createExactDomain('one.example.com');
        $owner = TestOwner::query()->create(['name' => 'Owner']);
        $other = TestOwner::query()->create(['name' => 'Other']);

        $owner->attachLaravelCloudDomain($domain->id);
        $owner->attachLaravelCloudDomain($domain->id);

        $this->expectException(LaravelCloudConflictException::class);
        $other->attachLaravelCloudDomain($domain->id);
    }

    public function test_create_response_cannot_transfer_an_existing_owned_domain(): void
    {
        $owner = TestOwner::query()->create(['name' => 'Original']);
        $other = TestOwner::query()->create(['name' => 'Other']);
        $this->client->nextCreateResult = $this->resource('remote-owned', 'owned.example.com');
        $domain = $owner->createLaravelCloudWildcardDomain('owned.example.com');
        try {
            $other->createLaravelCloudWildcardDomain('owned.example.com');
            $this->fail('Existing owner was replaced.');
        } catch (LaravelCloudConflictException) {
            $this->assertSame((string) $owner->getKey(), LaravelCloudDomain::findOrFail($domain->id)->attachable_id);
        }
    }

    public function test_configured_custom_domain_model_is_used(): void
    {
        config(['laravel-cloud.models.domain' => CustomDomain::class]);
        $this->client->nextCreateResult = $this->resource('remote-custom', 'custom.example.com');

        $data = $this->manager->createExactDomain('custom.example.com');

        $this->assertInstanceOf(CustomDomain::class, CustomDomain::query()->findOrFail($data->id));
    }

    public function test_string_owner_keys_are_preserved_by_polymorphic_relation(): void
    {
        foreach (['550e8400-e29b-41d4-a716-446655440000', '01J7F9QZ7A5M9G6K2X3W4V8BNC'] as $index => $id) {
            $owner = TestStringOwner::query()->create(['id' => $id, 'name' => "Owner $index"]);
            $this->client->nextCreateResult = $this->resource("remote-$index", "owner-$index.example.com");
            $owner->createLaravelCloudExactDomain("owner-$index.example.com");

            $this->assertSame($id, $owner->laravelCloudDomains()->firstOrFail()->attachable_id);
        }
    }

    public function test_timed_out_and_unknown_remote_statuses_are_mapped_safely(): void
    {
        $this->client->nextCreateResult = $this->resource('remote-timeout', 'timeout.example.com', [
            'action_required' => 'verification_timed_out',
        ]);
        $timedOut = $this->manager->createExactDomain('timeout.example.com');

        $this->client->nextCreateResult = $this->resource('remote-unknown', 'unknown.example.com', [
            'hostname_status' => 'future_status',
            'ssl_status' => 'active',
            'origin_status' => 'active',
        ]);
        $unknown = $this->manager->createExactDomain('unknown.example.com');

        $this->assertSame(DomainStatus::TimedOut, $timedOut->status);
        $this->assertSame(DomainStatus::Pending, $unknown->status);
        $this->assertSame('future_status', $unknown->hostnameStatus);
    }

    public function test_verify_refreshes_status_and_dns_records(): void
    {
        $this->client->nextCreateResult = $this->resource('remote-one', 'one.example.com');
        $domain = $this->manager->createExactDomain('one.example.com');
        $this->client->verificationResults['remote-one'] = $this->resource('remote-one', 'one.example.com', [
            'hostname_status' => 'active',
            'ssl_status' => 'active',
            'origin_status' => 'active',
            'dns_records' => ['origin' => ['type' => 'CNAME', 'name' => 'one.example.com', 'value' => 'origin.test']],
        ]);

        $verified = $this->manager->verifyDomain($domain->id);

        $this->assertSame(DomainStatus::Connected, $verified->status);
        $this->assertCount(1, $verified->dnsRecords);
        $this->assertNotNull($verified->lastSyncedAt);
    }

    public function test_dispatch_command_queues_only_pending_domain_ids_without_credentials(): void
    {
        Queue::fake();
        $this->client->nextCreateResult = $this->resource('remote-pending', 'pending.example.com');
        $pending = $this->manager->createExactDomain('pending.example.com');
        $this->client->nextCreateResult = $this->resource('remote-active', 'active.example.com', [
            'hostname_status' => 'active', 'ssl_status' => 'active', 'origin_status' => 'active',
        ]);
        $this->manager->createExactDomain('active.example.com');

        $this->artisan('laravel-cloud:dispatch-domain-checks')->assertSuccessful();

        Queue::assertPushed(CheckLaravelCloudDomainJob::class, 1);
        Queue::assertPushed(CheckLaravelCloudDomainJob::class, fn ($job): bool => $job->domainId === $pending->id);
        $this->assertStringNotContainsString('test-token', serialize(new CheckLaravelCloudDomainJob($pending->id)));
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function resource(string $id, string $name, array $overrides = []): array
    {
        return [
            'id' => $id,
            'type' => 'domains',
            'attributes' => [
                'name' => $name,
                'type' => 'root',
                'wildcard_enabled' => false,
                'hostname_status' => 'pending',
                'ssl_status' => 'pending',
                'origin_status' => 'pending',
                'dns_records' => [],
                'created_at' => '2026-09-10T00:00:00Z',
                ...$overrides,
            ],
        ];
    }
}
