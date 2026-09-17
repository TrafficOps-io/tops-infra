<?php

namespace TrafficOps\Cloudflare\Tests\Unit;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflareConflictException;
use TrafficOps\Cloudflare\Exceptions\CloudflareNotFoundException;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;
use TrafficOps\Cloudflare\Http\LaravelCloudflareClient;
use TrafficOps\Cloudflare\Tests\TestCase;

final class LaravelCloudflareClientTest extends TestCase
{
    public function test_paginates_zones_and_sends_bearer_token(): void
    {
        Http::fake([
            '*page=1*' => Http::response(['success' => true, 'result' => [['id' => 'one']], 'result_info' => ['total_pages' => 2]]),
            '*page=2*' => Http::response(['success' => true, 'result' => [['id' => 'two']], 'result_info' => ['total_pages' => 2]]),
        ]);

        $zones = (new LaravelCloudflareClient($this->app->make(Factory::class)))->listZones('secret');

        $this->assertSame(['one', 'two'], array_column($zones, 'id'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_maps_rate_limit_and_retry_after(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'Slow down']]], 429, ['Retry-After' => '42'])]);

        try {
            (new LaravelCloudflareClient($this->app->make(Factory::class)))->verifyToken('secret');
            $this->fail('Expected rate limit exception.');
        } catch (CloudflareRateLimitException $exception) {
            $this->assertSame(42, $exception->retryAfter);
            $this->assertSame('Slow down', $exception->getMessage());
        }
    }

    public function test_paginates_zones_using_total_count_when_total_pages_is_omitted(): void
    {
        Http::fakeSequence()
            ->push(['result' => [['id' => 'one'], ['id' => 'two']], 'result_info' => ['total_count' => 3, 'per_page' => 2]])
            ->push(['result' => [['id' => 'three']], 'result_info' => ['total_count' => 3, 'per_page' => 2]]);

        $zones = (new LaravelCloudflareClient($this->app->make(Factory::class)))->listZones('secret');

        $this->assertSame(['one', 'two', 'three'], array_column($zones, 'id'));
        Http::assertSentCount(2);
    }

    public function test_paginates_until_a_short_page_when_pagination_metadata_is_missing(): void
    {
        $first = array_map(fn ($id) => ['id' => (string) $id], range(1, 50));
        Http::fakeSequence()->push(['result' => $first])->push(['result' => [['id' => '51']]]);

        $zones = (new LaravelCloudflareClient($this->app->make(Factory::class)))->listZones('secret');

        $this->assertCount(51, $zones);
        $this->assertSame('51', $zones[50]['id']);
        Http::assertSentCount(2);
    }

    public function test_does_not_return_a_partial_zone_list_when_a_later_page_fails(): void
    {
        Http::fakeSequence()
            ->push(['result' => [['id' => 'one']], 'result_info' => ['total_pages' => 2]])
            ->push(['success' => false], 403);

        $this->expectException(CloudflarePermissionException::class);
        (new LaravelCloudflareClient($this->app->make(Factory::class)))->listZones('secret');
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('errorStatuses')]
    public function test_maps_http_errors_to_typed_exceptions(int $status, string $exception): void
    {
        config(['cloudflare.api.retries' => 0]);
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'API error']]], $status)]);

        $this->expectException($exception);
        (new LaravelCloudflareClient($this->app->make(Factory::class)))->verifyToken('secret');
    }

    public static function errorStatuses(): array
    {
        return [
            [401, CloudflareAuthenticationException::class],
            [403, CloudflarePermissionException::class],
            [404, CloudflareNotFoundException::class],
            [409, CloudflareConflictException::class],
            [422, CloudflareValidationException::class],
            [500, CloudflareTransportException::class],
        ];
    }
}
