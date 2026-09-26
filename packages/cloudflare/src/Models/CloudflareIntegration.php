<?php

namespace TrafficOps\Cloudflare\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Support\ModelResolver;

class CloudflareIntegration extends Model
{
    use HasUlids;

    protected $table = 'cloudflare_integrations';

    protected $guarded = [];

    protected $hidden = ['api_token', 'token_fingerprint'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'status' => IntegrationStatus::class,
            'token_expires_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ModelResolver::class('account'), 'integration_id');
    }

    /**
     * Integrations whose claims a Check may still examine.
     *
     * An Invalid integration (revoked or expired token, missing scopes) is not
     * checked until it is reconnected, and a token past its expiry is treated
     * as Invalid even before a Sync has observed the failure.
     */
    public function scopeCheckable(Builder $query): Builder
    {
        return $query
            ->where('status', '!=', IntegrationStatus::Invalid->value)
            ->where(fn (Builder $query) => $query
                ->whereNull('token_expires_at')
                ->orWhere('token_expires_at', '>', now()));
    }
}
