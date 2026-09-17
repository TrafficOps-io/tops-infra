<?php

namespace TrafficOps\LaravelCloud\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TrafficOps\LaravelCloud\Enums\DomainStatus;

class LaravelCloudDomain extends Model
{
    use HasUlids;

    protected $table = 'laravel_cloud_domains';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'wildcard_enabled' => 'boolean',
            'allow_downtime' => 'boolean',
            'status' => DomainStatus::class,
            'variant_statuses' => 'array',
            'remote_payload' => 'array',
            'remote_created_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function dnsRecords(): HasMany
    {
        return $this->hasMany(LaravelCloudDomainRecord::class, 'domain_id')
            ->orderBy('scope')->orderBy('purpose')->orderBy('position');
    }
}
