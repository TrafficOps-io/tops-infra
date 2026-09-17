<?php

namespace TrafficOps\LaravelCloud\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TrafficOps\LaravelCloud\Support\ModelResolver;

class LaravelCloudDomainRecord extends Model
{
    use HasUlids;

    protected $table = 'laravel_cloud_domain_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['position' => 'integer', 'payload' => 'json'];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class(), 'domain_id');
    }
}
