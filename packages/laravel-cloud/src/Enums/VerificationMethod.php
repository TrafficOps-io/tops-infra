<?php

namespace TrafficOps\LaravelCloud\Enums;

enum VerificationMethod: string
{
    case RealTime = 'real_time';
    case PreVerification = 'pre_verification';
}
