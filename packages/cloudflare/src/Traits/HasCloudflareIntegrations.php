<?php

namespace TrafficOps\Cloudflare\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\Relations\StringMorphMany;
use TrafficOps\Cloudflare\Support\ModelResolver;

trait HasCloudflareIntegrations
{
    public function cloudflareIntegrations(): MorphMany
    {
        $instance = $this->newRelatedInstance(ModelResolver::class('integration'));

        return new StringMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn('owner_type'),
            $instance->qualifyColumn('owner_id'),
            $this->getKeyName(),
        );
    }

    public function connectCloudflare(string $apiToken, ?string $label = null): IntegrationData
    {
        return app(CloudflareManagerContract::class)->connect($this, $apiToken, $label);
    }
}
