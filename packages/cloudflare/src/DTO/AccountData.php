<?php

namespace TrafficOps\Cloudflare\DTO;

use TrafficOps\Cloudflare\Models\CloudflareAccount;

final readonly class AccountData
{
    public function __construct(public string $id, public string $cloudflareId, public string $name, public string $status) {}

    public static function fromModel(CloudflareAccount $account): self
    {
        return new self((string) $account->getKey(), $account->cloudflare_id, $account->name, $account->status);
    }
}
