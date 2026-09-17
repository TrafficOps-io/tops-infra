<?php

namespace TrafficOps\LaravelCloud\Enums;

enum CloudflareStrategy: string
{
    case None = 'none';
    case Dns = 'dns';
    case DnsProxy = 'dns_proxy';
}
