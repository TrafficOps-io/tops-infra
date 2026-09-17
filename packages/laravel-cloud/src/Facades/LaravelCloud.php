<?php

namespace TrafficOps\LaravelCloud\Facades;

use Illuminate\Support\Facades\Facade;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudManagerContract;

/** @see LaravelCloudManagerContract */
final class LaravelCloud extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LaravelCloudManagerContract::class;
    }
}
