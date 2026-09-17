<?php

namespace TrafficOps\LaravelCloud\DTO;

use TrafficOps\LaravelCloud\Enums\CloudflareStrategy;
use TrafficOps\LaravelCloud\Enums\VerificationMethod;
use TrafficOps\LaravelCloud\Enums\WwwRedirect;

final readonly class DomainOptions
{
    public function __construct(
        public ?WwwRedirect $wwwRedirect = null,
        public bool $allowDowntime = true,
        public CloudflareStrategy $cloudflareStrategy = CloudflareStrategy::None,
        public VerificationMethod $verificationMethod = VerificationMethod::RealTime,
    ) {}

    public static function exact(): self
    {
        return new self;
    }

    public static function wildcard(): self
    {
        return new self(
            allowDowntime: false,
            verificationMethod: VerificationMethod::PreVerification,
        );
    }

    /** @return array<string, bool|string> */
    public function toArray(): array
    {
        return array_filter([
            'www_redirect' => $this->wwwRedirect?->value,
            'allow_downtime' => $this->allowDowntime,
            'cloudflare_strategy' => $this->cloudflareStrategy->value,
            'verification_method' => $this->verificationMethod->value,
        ], fn (mixed $value): bool => $value !== null);
    }
}
