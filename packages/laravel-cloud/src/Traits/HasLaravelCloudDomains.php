<?php

namespace TrafficOps\LaravelCloud\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use TrafficOps\LaravelCloud\Contracts\LaravelCloudManagerContract;
use TrafficOps\LaravelCloud\DTO\DomainData;
use TrafficOps\LaravelCloud\DTO\DomainOptions;
use TrafficOps\LaravelCloud\Relations\StringMorphMany;
use TrafficOps\LaravelCloud\Support\ModelResolver;

trait HasLaravelCloudDomains
{
    public function laravelCloudDomains(): MorphMany
    {
        $instance = $this->newRelatedInstance(ModelResolver::class());

        return new StringMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn('attachable_type'),
            $instance->qualifyColumn('attachable_id'),
            $this->getKeyName(),
        );
    }

    public function createLaravelCloudExactDomain(string $name, ?DomainOptions $options = null): DomainData
    {
        return app(LaravelCloudManagerContract::class)->createExactDomain($name, $this, $options);
    }

    public function createLaravelCloudWildcardDomain(string $name, ?DomainOptions $options = null): DomainData
    {
        return app(LaravelCloudManagerContract::class)->createWildcardDomain($name, $this, $options);
    }

    public function attachLaravelCloudDomain(string $localDomainId): DomainData
    {
        return app(LaravelCloudManagerContract::class)->attachDomain($localDomainId, $this);
    }
}
