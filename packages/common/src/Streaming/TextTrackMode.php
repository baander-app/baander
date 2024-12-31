<?php

namespace Baander\Common\Streaming;

use Baander\Extensions\EnumExt;

enum TextTrackMode : string
{
    use EnumExt;

    case BurnIn = 'burn_in';
    case External = 'external';
    case Hls = 'hls';
}