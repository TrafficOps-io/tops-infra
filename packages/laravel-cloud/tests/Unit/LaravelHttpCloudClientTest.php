<?php

namespace TrafficOps\LaravelCloud\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudClientContract;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudRateLimitException;
use TrafficOps\LaravelCloud\Tests\TestCase;

final class LaravelHttpCloudClientTest extends TestCase
{
    public function test_lists_all_pages_with_bearer_authentication(): void
    {
        config(['laravel-cloud.api.base_url' => 'https://cloud.test/api']);
        Http::fakeSequence()
            ->push(['data' => [['id' => 'one']], 'meta' => ['last_page' => 2]])
            ->push(['data' => [['id' => 'two']], 'meta' => ['last_page' => 2]]);

        $domains = $this->app->make(LaravelCloudClientContract::class)->listDomains('environment-one');

        $this->assertSame(['one', 'two'], array_column($domains, 'id'));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://cloud.test/api/environments/environment-one/domains?page=1'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_create_posts_json_and_unwraps_json_api_resource(): void
    {
        config(['laravel-cloud.api.base_url' => 'https://cloud.test/api']);
        Http::fake(['*' => Http::response(['data' => ['id' => 'domain-one', 'attributes' => ['name' => 'example.com']]])]);

        $domain = $this->app->make(LaravelCloudClientContract::class)->createDomain('environment-one', [
            'name' => 'example.com',
            'wildcard_enabled' => false,
        ]);

        $this->assertSame('domain-one', $domain['id']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://cloud.test/api/environments/environment-one/domains'
            && $request['wildcard_enabled'] === false);
    }

    public function test_maps_rate_limit_retry_after(): void
    {
        config(['laravel-cloud.api.base_url' => 'https://cloud.test/api']);
        Http::fake(['*' => Http::response(['message' => 'Slow down'], 429, ['Retry-After' => '42'])]);

        try {
            $this->app->make(LaravelCloudClientContract::class)->verifyDomain('domain-one');
            $this->fail('Expected a rate limit exception.');
        } catch (LaravelCloudRateLimitException $exception) {
            $this->assertSame(42, $exception->retryAfter);
            $this->assertSame('Slow down', $exception->getMessage());
        }
    }
}
