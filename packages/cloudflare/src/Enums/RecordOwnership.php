<?php

namespace TrafficOps\Cloudflare\Enums;

enum RecordOwnership: string
{
    case Managed = 'managed';
    case Adopted = 'adopted';
}
