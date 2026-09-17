<?php

namespace TrafficOps\Cloudflare\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TrafficOps\Cloudflare\Enums\DomainStatus;
use TrafficOps\Cloudflare\Support\ModelResolver;

class CloudflareDomain extends Model
{
    use HasUlids;

    protected $table = 'cloudflare_domains';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => DomainStatus::class, 'last_checked_at' => 'immutable_datetime'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class('zone'), 'zone_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ModelResolver::class('record'), 'domain_id');
    }
}
