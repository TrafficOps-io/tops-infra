<?php

namespace TrafficOps\LaravelCloud\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use TrafficOps\LaravelCloud\Traits\HasLaravelCloudDomains;

final class TestOwner extends Model
{
    use HasLaravelCloudDomains;

    protected $table = 'test_owners';

    protected $guarded = [];
}
