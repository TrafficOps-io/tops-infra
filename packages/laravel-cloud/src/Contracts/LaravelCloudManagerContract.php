<?php

namespace TrafficOps\LaravelCloud\Contracts;

use Illuminate\Database\Eloquent\Model;
use TrafficOps\LaravelCloud\DTO\DomainData;
use TrafficOps\LaravelCloud\DTO\DomainOptions;

interface LaravelCloudManagerContract
{
    /** @return list<DomainData> */
    public function domains(): array;

    public function createExactDomain(string $name, ?Model $attachable = null, ?DomainOptions $options = null): DomainData;

    public function createWildcardDomain(string $name, ?Model $attachable = null, ?DomainOptions $options = null): DomainData;

    public function domainStatus(string $localDomainId): DomainData;

    public function verifyDomain(string $localDomainId): DomainData;

    public function attachDomain(string $localDomainId, Model $attachable): DomainData;
}
