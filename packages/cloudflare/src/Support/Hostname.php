<?php

namespace TrafficOps\Cloudflare\Support;

use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;

final class Hostname
{
    public static function normalize(string $hostname, bool $allowWildcard = false, bool $allowUnderscore = false): string
    {
        $hostname = mb_strtolower(rtrim(trim($hostname), '.'));
        $wildcard = str_starts_with($hostname, '*.');

        if (str_contains($hostname, '*') && (! $allowWildcard || ! $wildcard || substr_count($hostname, '*') !== 1)) {
            throw new CloudflareValidationException('A wildcard is only allowed as the first `*.` label.');
        }

        $plain = $wildcard ? substr($hostname, 2) : $hostname;
        $ascii = idn_to_ascii($plain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || strlen($ascii) > 253 || ! self::isValidAsciiHostname($ascii, $allowUnderscore)) {
            throw new CloudflareValidationException("Invalid hostname [$hostname].");
        }

        return $wildcard ? '*.'.$ascii : $ascii;
    }

    public static function belongsToZone(string $hostname, string $zone): bool
    {
        $plain = str_starts_with($hostname, '*.') ? substr($hostname, 2) : $hostname;

        return $plain === $zone || str_ends_with($plain, '.'.$zone);
    }

    public static function overlaps(string $left, string $right): bool
    {
        if ($left === $right) {
            return true;
        }

        $leftWildcard = str_starts_with($left, '*.');
        $rightWildcard = str_starts_with($right, '*.');
        $leftBase = $leftWildcard ? substr($left, 2) : $left;
        $rightBase = $rightWildcard ? substr($right, 2) : $right;

        if ($leftWildcard && ! $rightWildcard) {
            return str_ends_with($rightBase, '.'.$leftBase);
        }

        if (! $leftWildcard && $rightWildcard) {
            return str_ends_with($leftBase, '.'.$rightBase);
        }

        return $leftWildcard && $rightWildcard
            && (str_ends_with($leftBase, '.'.$rightBase) || str_ends_with($rightBase, '.'.$leftBase));
    }

    public static function wildcardProbe(string $hostname, string $seed): string
    {
        $base = substr($hostname, 2);

        return '_cf-check-'.substr(hash('sha256', $seed), 0, 12).'.'.$base;
    }

    private static function isValidAsciiHostname(string $hostname, bool $allowUnderscore): bool
    {
        if (! str_contains($hostname, '.') || str_starts_with($hostname, '.') || str_ends_with($hostname, '.')) {
            return false;
        }

        foreach (explode('.', $hostname) as $label) {
            $pattern = $allowUnderscore
                ? '/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?$/'
                : '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

            if ($label === '' || strlen($label) > 63 || ! preg_match($pattern, $label)) {
                return false;
            }
        }

        return true;
    }
}
