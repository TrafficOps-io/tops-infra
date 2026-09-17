<?php

namespace TrafficOps\LaravelCloud\Support;

use TrafficOps\LaravelCloud\Exceptions\LaravelCloudValidationException;

final class Hostname
{
    public static function exact(string $hostname): string
    {
        if (str_contains($hostname, '*')) {
            throw new LaravelCloudValidationException('Exact domains cannot contain a wildcard.');
        }

        return self::normalize($hostname);
    }

    public static function wildcardBase(string $hostname): string
    {
        $hostname = trim($hostname);
        if (str_starts_with($hostname, '*.')) {
            $hostname = substr($hostname, 2);
        }

        if (str_contains($hostname, '*')) {
            throw new LaravelCloudValidationException('A wildcard is only allowed as the first `*.` label.');
        }

        return self::normalize($hostname);
    }

    private static function normalize(string $hostname): string
    {
        $hostname = mb_strtolower(rtrim(trim($hostname), '.'));
        $ascii = idn_to_ascii($hostname, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if ($ascii === false || strlen($ascii) > 253 || ! str_contains($ascii, '.')) {
            throw new LaravelCloudValidationException("Invalid hostname [$hostname].");
        }

        foreach (explode('.', $ascii) as $label) {
            if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label)) {
                throw new LaravelCloudValidationException("Invalid hostname [$hostname].");
            }
        }

        return $ascii;
    }
}
