<?php

namespace TrafficOps\Cloudflare\Dns;

use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\Support\Hostname;

/** Read-only comparison, shared by managed DNS and manual DNS consumers. */
class PublicDnsRecordChecker
{
    public function __construct(private PublicDnsResolverContract $resolver) {}

    /** @return array{status: string, name: string, answers: array, evidence: string} */
    public function check(DnsRecordExpectation $record, string $seed, bool $allowFlattening = false): array
    {
        $name = str_starts_with($record->name, '*.') ? Hostname::wildcardProbe($record->name, $seed) : $record->name;
        $type = $record->proxied === true && $record->type === 'CNAME' ? 'A' : $record->type;
        $answers = $this->resolver->resolve($name, $type)->answers;
        $matched = $record->proxied === true ? $answers !== [] : in_array($this->normalize($record->content, $type), array_map(fn ($value) => $this->normalize($value, $type), $answers), true);
        $evidence = 'direct';
        if (! $matched && $answers === [] && $type === 'CNAME' && $allowFlattening) {
            $addresses = $targets = [];
            foreach (['A', 'AAAA'] as $addressType) {
                $addresses[$addressType] = $this->resolver->resolve($name, $addressType)->answers;
                $targets[$addressType] = $this->resolver->resolve($record->content, $addressType)->answers;
            }
            $actual = array_merge(...array_values($addresses));
            $target = array_merge(...array_values($targets));
            $normalizedActual = array_map(fn ($ip) => $this->normalize($ip, 'A'), $actual);
            $normalizedTarget = array_map(fn ($ip) => $this->normalize($ip, 'A'), $target);
            $matched = $actual !== [] && array_diff($normalizedActual, $normalizedTarget) === [];
            $answers = ['addresses' => $addresses, 'target_addresses' => $targets];
            $evidence = 'flattened_addresses';

            // Different anycast answers do not prove a wrong CNAME. Cloud's origin status is independent.
            return ['status' => $actual === [] ? 'missing' : ($matched ? 'matched' : 'indeterminate'), 'name' => $name, 'answers' => $answers, 'evidence' => $evidence];
        }

        return ['status' => $matched ? 'matched' : ($answers === [] ? 'missing' : 'mismatched'), 'name' => $name, 'answers' => $answers, 'evidence' => $evidence];
    }

    private function normalize(string $value, string $type): string
    {
        if (in_array($type, ['A', 'AAAA'], true)) {
            $binary = @inet_pton($value);

            return $binary === false ? $value : bin2hex($binary);
        }

        return $type === 'CNAME' ? strtolower(rtrim($value, '.')) : $value;
    }
}
