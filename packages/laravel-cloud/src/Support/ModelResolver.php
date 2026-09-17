<?php

namespace TrafficOps\LaravelCloud\Support;

use InvalidArgumentException;
use TrafficOps\LaravelCloud\Models\LaravelCloudDomain;

final class ModelResolver
{
    /** @return class-string<LaravelCloudDomain> */
    public static function class(): string
    {
        $class = config('laravel-cloud.models.domain');

        if (! is_string($class) || ! is_a($class, LaravelCloudDomain::class, true)) {
            throw new InvalidArgumentException('laravel-cloud.models.domain must extend '.LaravelCloudDomain::class.'.');
        }

        return $class;
    }
}
