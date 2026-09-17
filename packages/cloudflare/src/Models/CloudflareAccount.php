<?php

namespace TrafficOps\Cloudflare\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TrafficOps\Cloudflare\Support\ModelResolver;

class CloudflareAccount extends Model
{
    use HasUlids;

    protected $table = 'cloudflare_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_synced_at' => 'immutable_datetime'];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class('integration'), 'integration_id');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(ModelResolver::class('zone'), 'account_id');
    }
}
