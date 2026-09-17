<?php

namespace TrafficOps\Cloudflare\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TrafficOps\Cloudflare\Support\ModelResolver;

class CloudflareZone extends Model
{
    use HasUlids;

    protected $table = 'cloudflare_zones';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paused' => 'boolean', 'name_servers' => 'array', 'last_synced_at' => 'immutable_datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class('account'), 'account_id');
    }

    public function domains(): HasMany
    {
        return $this->hasMany(ModelResolver::class('domain'), 'zone_id');
    }
}
