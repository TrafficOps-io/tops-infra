<?php

namespace TrafficOps\Cloudflare\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\Enums\RecordOwnership;
use TrafficOps\Cloudflare\Enums\RecordStatus;
use TrafficOps\Cloudflare\Support\ModelResolver;

class CloudflareDomainRecord extends Model
{
    use HasUlids;

    protected $table = 'cloudflare_domain_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'desired' => 'boolean',
            'proxied' => 'boolean',
            'ownership' => RecordOwnership::class,
            'control_status' => RecordStatus::class,
            'public_status' => RecordStatus::class,
            'last_checked_at' => 'immutable_datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(ModelResolver::class('domain'), 'domain_id');
    }

    public function expectation(): DnsRecordExpectation
    {
        return new DnsRecordExpectation($this->type, $this->name, $this->content, $this->ttl, $this->proxied);
    }
}
