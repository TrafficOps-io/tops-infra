<?php

namespace TrafficOps\Cloudflare\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\DTO\DnsLookupResult;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Support\Hostname;

final class CloudflareDohResolver implements PublicDnsResolverContract
{
    private const TYPES = ['A' => 1, 'CNAME' => 5, 'TXT' => 16, 'AAAA' => 28];

    public function __construct(private readonly Factory $http) {}

    public function resolve(string $name, string $type): DnsLookupResult
    {
        $type = strtoupper($type);
        if (! isset(self::TYPES[$type])) {
            throw new CloudflareTransportException("Unsupported public DNS lookup type [$type].");
        }

        try {
            $response = $this->http
                ->accept('application/dns-json')
                ->connectTimeout((int) config('cloudflare.api.connect_timeout', 5))
                ->timeout((int) config('cloudflare.api.timeout', 15))
                ->get((string) config('cloudflare.dns.resolver_url'), ['name' => $name, 'type' => $type]);
        } catch (ConnectionException $exception) {
            throw new CloudflareTransportException('Public DNS resolver is unreachable.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new CloudflareTransportException("Public DNS resolver returned HTTP {$response->status()}.");
        }

        $dnsStatus = (int) $response->json('Status', 0);
        if (! in_array($dnsStatus, [0, 3], true)) {
            throw new CloudflareTransportException("Public DNS resolver returned DNS status [$dnsStatus].");
        }

        $answers = collect($response->json('Answer', []))
            ->filter(fn ($answer) => is_array($answer) && (int) ($answer['type'] ?? 0) === self::TYPES[$type])
            ->map(function (array $answer) use ($type) {
                $value = (string) ($answer['data'] ?? '');

                return match ($type) {
                    'CNAME' => Hostname::normalize(rtrim($value, '.')),
                    'TXT' => $this->normalizeTxt($value),
                    default => $value,
                };
            })
            ->values()
            ->all();

        return new DnsLookupResult($name, $type, $answers);
    }

    private function normalizeTxt(string $value): string
    {
        if (preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/', $value, $segments) && $segments[1] !== []) {
            return implode('', array_map('stripcslashes', $segments[1]));
        }

        return $value;
    }
}
