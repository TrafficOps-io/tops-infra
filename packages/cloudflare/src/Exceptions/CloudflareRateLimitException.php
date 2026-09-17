<?php

namespace TrafficOps\Cloudflare\Exceptions;

class CloudflareRateLimitException extends CloudflareException
{
    public function __construct(string $message, public readonly int $retryAfter = 60)
    {
        parent::__construct($message, 429);
    }
}
