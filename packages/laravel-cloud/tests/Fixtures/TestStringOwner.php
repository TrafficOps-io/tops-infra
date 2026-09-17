<?php

namespace TrafficOps\LaravelCloud\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use TrafficOps\LaravelCloud\Traits\HasLaravelCloudDomains;

final class TestStringOwner extends Model
{
    use HasLaravelCloudDomains;

    public $incrementing = false;

    protected $table = 'test_string_owners';

    protected $keyType = 'string';

    protected $guarded = [];
}
