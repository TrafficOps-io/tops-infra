<?php

namespace TrafficOps\LaravelCloud\DTO;

use TrafficOps\LaravelCloud\Models\LaravelCloudDomainRecord;

final readonly class DnsRecordData
{
    /** @param array<string, mixed>|list<mixed>|string|int|float|bool|null $payload */
    public function __construct(
        public string $scope,
        public string $purpose,
        public int $position,
        public ?string $type,
        public ?string $name,
        public ?string $value,
        public array|string|int|float|bool|null $payload,
    ) {}

    public static function fromModel(LaravelCloudDomainRecord $record): self
    {
        return new self(
            $record->scope,
            $record->purpose,
            $record->position,
            $record->type,
            $record->name,
            $record->value,
            $record->payload,
        );
    }
}
