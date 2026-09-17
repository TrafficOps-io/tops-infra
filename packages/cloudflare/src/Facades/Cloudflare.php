<?php

namespace TrafficOps\Cloudflare\Facades;

use Illuminate\Support\Facades\Facade;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;

/** @see CloudflareManagerContract */
final class Cloudflare extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CloudflareManagerContract::class;
    }
}
