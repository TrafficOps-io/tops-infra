<?php

namespace TrafficOps\Cloudflare\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use TrafficOps\Cloudflare\Traits\HasCloudflareIntegrations;

final class TestOwner extends Model
{
    use HasCloudflareIntegrations;

    protected $table = 'test_owners';

    protected $guarded = [];
}
