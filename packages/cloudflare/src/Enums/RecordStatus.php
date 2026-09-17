<?php

namespace TrafficOps\Cloudflare\Enums;

enum RecordStatus: string
{
    case Pending = 'pending';
    case Matched = 'matched';
    case Missing = 'missing';
    case Mismatched = 'mismatched';
    case Unreachable = 'unreachable';
}
