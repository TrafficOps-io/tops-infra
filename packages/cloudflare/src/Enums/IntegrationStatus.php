<?php

namespace TrafficOps\Cloudflare\Enums;

enum IntegrationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Degraded = 'degraded';
    case Invalid = 'invalid';
    case Unreachable = 'unreachable';
}
