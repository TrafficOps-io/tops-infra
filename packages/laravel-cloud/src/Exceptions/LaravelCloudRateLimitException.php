<?php

namespace TrafficOps\LaravelCloud\Exceptions;

class LaravelCloudRateLimitException extends LaravelCloudException
{
    public function __construct(string $message, public readonly int $retryAfter, int $code = 429)
    {
        parent::__construct($message, $code);
    }
}
