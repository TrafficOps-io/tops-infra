<?php

namespace TrafficOps\LaravelCloud\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case TimedOut = 'timed_out';
    case Detached = 'detached';
}
