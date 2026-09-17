<?php

namespace TrafficOps\LaravelCloud\Enums;

enum WwwRedirect: string
{
    case RootToWww = 'root_to_www';
    case WwwToRoot = 'www_to_root';
}
