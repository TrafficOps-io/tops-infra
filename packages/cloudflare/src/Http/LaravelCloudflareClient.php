<?php

namespace TrafficOps\Cloudflare\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;
use TrafficOps\Cloudflare\Contracts\CloudflareClientContract;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflareConflictException;
use TrafficOps\Cloudflare\Exceptions\CloudflareException;
use TrafficOps\Cloudflare\Exceptions\CloudflareNotFoundException;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;

final class LaravelCloudflareClient implements CloudflareClientContract
{
    public function __construct(private readonly Factory $http) {}

    public function verifyToken(string $token): array
    {
        return $this->get($token, '/user/tokens/verify');
    }

    public function listZones(string $token): array
    {
        $zones = [];
        $page = 1;

        do {
            $payload = $this->getEnvelope($token, '/zones', ['page' => $page, 'per_page' => 50]);
            $results = array_values($payload['result'] ?? []);
            array_push($zones, ...$results);
            $info = $payload['result_info'] ?? [];
            $perPage = max(1, (int) ($info['per_page'] ?? 50));
            $hasMore = match (true) {
                isset($info['total_pages']) => $page < (int) $info['total_pages'],
                isset($info['total_count']) => $page * $perPage < (int) $info['total_count'],
                default => count($results) >= $perPage,
            };
            $page++;
        } while ($results !== [] && $hasMore);

        return $zones;
    }

    public function listDnsRecords(string $token, string $zoneId): array
    {
        $records = [];
        $page = 1;

        do {
            $payload = $this->getEnvelope($token, "/zones/$zoneId/dns_records", ['page' => $page, 'per_page' => 5000]);
            array_push($records, ...array_values($payload['result'] ?? []));
            $pages = max(1, (int) ($payload['result_info']['total_pages'] ?? 1));
            $page++;
        } while ($page <= $pages);

        return $records;
    }

    public function createDnsRecord(string $token, string $zoneId, array $record): array
    {
        return $this->write('post', $token, "/zones/$zoneId/dns_records", $record);
    }

    public function updateDnsRecord(string $token, string $zoneId, string $recordId, array $record): array
    {
        return $this->write('patch', $token, "/zones/$zoneId/dns_records/$recordId", $record);
    }

    public function deleteDnsRecord(string $token, string $zoneId, string $recordId): void
    {
        $this->write('delete', $token, "/zones/$zoneId/dns_records/$recordId", []);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function get(string $token, string $uri, array $query = []): array
    {
        $payload = $this->getEnvelope($token, $uri, $query);

        return (array) ($payload['result'] ?? []);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function getEnvelope(string $token, string $uri, array $query = []): array
    {
        $attempts = max(1, (int) config('cloudflare.api.retries', 2) + 1);
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->request($token)->get($uri, $query);
                $this->guard($response);

                return (array) $response->json();
            } catch (CloudflareRateLimitException $exception) {
                throw $exception;
            } catch (ConnectionException|CloudflareTransportException $exception) {
                $last = $exception;
                if ($attempt < $attempts) {
                    usleep(random_int(50_000, 150_000) * $attempt);
                }
            }
        }

        throw new CloudflareTransportException('Cloudflare API is unreachable.', previous: $last);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function write(string $method, string $token, string $uri, array $payload): array
    {
        try {
            $response = $method === 'delete'
                ? $this->request($token)->delete($uri)
                : $this->request($token)->{$method}($uri, $payload);
        } catch (ConnectionException $exception) {
            throw new CloudflareTransportException('Cloudflare API is unreachable during a write operation.', previous: $exception);
        }

        $this->guard($response);

        return (array) ($response->json('result') ?? []);
    }

    private function request(string $token): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim((string) config('cloudflare.api.base_url'), '/'))
            ->acceptJson()
            ->withToken($token)
            ->connectTimeout((int) config('cloudflare.api.connect_timeout', 5))
            ->timeout((int) config('cloudflare.api.timeout', 15));
    }

    private function guard(Response $response): void
    {
        $success = $response->successful() && $response->json('success') !== false;

        if ($success) {
            return;
        }

        $message = $this->safeMessage($response);

        throw match ($response->status()) {
            401 => new CloudflareAuthenticationException($message, 401),
            403 => new CloudflarePermissionException($message, 403),
            404 => new CloudflareNotFoundException($message, 404),
            409 => new CloudflareConflictException($message, 409),
            422 => new CloudflareValidationException($message, 422),
            429 => new CloudflareRateLimitException($message, max(1, (int) $response->header('Retry-After', 60))),
            default => $response->serverError()
                ? new CloudflareTransportException($message, $response->status())
                : new CloudflareException($message, $response->status()),
        };
    }

    private function safeMessage(Response $response): string
    {
        try {
            $messages = collect($response->json('errors', []))
                ->pluck('message')
                ->filter(fn ($message) => is_string($message))
                ->take(3)
                ->implode('; ');
        } catch (Throwable) {
            $messages = '';
        }

        return $messages !== '' ? $messages : "Cloudflare API request failed with HTTP {$response->status()}.";
    }
}
