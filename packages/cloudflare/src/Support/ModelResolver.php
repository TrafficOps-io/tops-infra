<?php

namespace TrafficOps\Cloudflare\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use TrafficOps\Cloudflare\Models\CloudflareAccount;
use TrafficOps\Cloudflare\Models\CloudflareDomain;
use TrafficOps\Cloudflare\Models\CloudflareDomainRecord;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;
use TrafficOps\Cloudflare\Models\CloudflareZone;

final class ModelResolver
{
    /** @return class-string<Model> */
    public static function class(string $name): string
    {
        $class = config("cloudflare.models.$name");
        $base = match ($name) {
            'integration' => CloudflareIntegration::class,
            'account' => CloudflareAccount::class,
            'zone' => CloudflareZone::class,
            'domain' => CloudflareDomain::class,
            'record' => CloudflareDomainRecord::class,
            default => throw new InvalidArgumentException("Unknown Cloudflare model [$name]."),
        };

        if (! is_string($class) || ! is_a($class, $base, true)) {
            throw new InvalidArgumentException("Cloudflare model [$name] must extend [$base].");
        }

        return $class;
    }

    public static function make(string $name): Model
    {
        $class = self::class($name);

        return new $class;
    }
}
