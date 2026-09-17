<?php

namespace TrafficOps\LaravelCloud\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudClientContract;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudAuthenticationException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudConflictException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudNotFoundException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudPermissionException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudRateLimitException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudTransportException;
use TrafficOps\LaravelCloud\Exceptions\LaravelCloudValidationException;

final class LaravelHttpCloudClient implements LaravelCloudClientContract
{
    public function __construct(private readonly Factory $http) {}

    public function listDomains(string $environmentId): array
    {
        $domains = [];
        $page = 1;

        do {
            $payload = $this->get("/environments/$environmentId/domains", ['page' => $page]);
            array_push($domains, ...array_values((array) ($payload['data'] ?? [])));
            $lastPage = max(1, (int) ($payload['meta']['last_page'] ?? 1));
            $page++;
        } while ($page <= $lastPage);

        return $domains;
    }

    public function createDomain(string $environmentId, array $attributes): array
    {
        return $this->write('post', "/environments/$environmentId/domains", $attributes);
    }

    public function getDomain(string $domainId): array
    {
        return $this->resource($this->get("/domains/$domainId"));
    }

    public function verifyDomain(string $domainId): array
    {
        return $this->write('post', "/domains/$domainId/verify", []);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function get(string $uri, array $query = []): array
    {
        $attempts = max(1, (int) config('laravel-cloud.api.retries', 2) + 1);
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->request()->get($uri, $query);
                $this->guard($response);

                return (array) $response->json();
            } catch (LaravelCloudRateLimitException|LaravelCloudAuthenticationException|LaravelCloudPermissionException|LaravelCloudNotFoundException|LaravelCloudValidationException|LaravelCloudConflictException $exception) {
                throw $exception;
            } catch (ConnectionException|LaravelCloudTransportException $exception) {
                $last = $exception;
                if ($attempt < $attempts) {
                    usleep(random_int(50_000, 150_000) * $attempt);
                }
            }
        }

        throw new LaravelCloudTransportException('Laravel Cloud API is unreachable.', previous: $last);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function write(string $method, string $uri, array $payload): array
    {
        try {
            $response = $this->request()->{$method}($uri, $payload);
        } catch (ConnectionException $exception) {
            throw new LaravelCloudTransportException('Laravel Cloud API is unreachable during a write operation.', previous: $exception);
        }

        $this->guard($response);

        return $this->resource((array) $response->json());
    }

    private function request(): PendingRequest
    {
        $token = trim((string) config('laravel-cloud.api.token'));
        if ($token === '') {
            throw new LaravelCloudValidationException('Laravel Cloud API token is not configured.');
        }

        return $this->http
            ->baseUrl(rtrim((string) config('laravel-cloud.api.base_url'), '/'))
            ->accept('application/vnd.api+json')
            ->asJson()
            ->withToken($token)
            ->connectTimeout((int) config('laravel-cloud.api.connect_timeout', 5))
            ->timeout((int) config('laravel-cloud.api.timeout', 15));
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $this->safeMessage($response);

        throw match ($response->status()) {
            401 => new LaravelCloudAuthenticationException($message, 401),
            403 => new LaravelCloudPermissionException($message, 403),
            404 => new LaravelCloudNotFoundException($message, 404),
            409 => new LaravelCloudConflictException($message, 409),
            422 => new LaravelCloudValidationException($message, 422),
            429 => new LaravelCloudRateLimitException($message, max(1, (int) $response->header('Retry-After', 60))),
            default => $response->serverError()
                ? new LaravelCloudTransportException($message, $response->status())
                : new LaravelCloudException($message, $response->status()),
        };
    }

    private function safeMessage(Response $response): string
    {
        try {
            $detail = $response->json('message');
            if (is_string($detail) && $detail !== '') {
                return $detail;
            }

            $messages = collect($response->json('errors', []))
                ->map(fn (mixed $error): mixed => is_array($error) ? ($error['detail'] ?? $error['message'] ?? null) : $error)
                ->filter(fn (mixed $message): bool => is_string($message))
                ->take(3)
                ->implode('; ');
        } catch (Throwable) {
            $messages = '';
        }

        return $messages !== '' ? $messages : "Laravel Cloud API request failed with HTTP {$response->status()}.";
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function resource(array $payload): array
    {
        return (array) ($payload['data'] ?? $payload);
    }
}
