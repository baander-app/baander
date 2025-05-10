<?php

namespace Baander\Transcoder;

use Baander\Extensions\EnumExt;

enum TranscoderPubSubChannel: string
{
    use EnumExt;

    case Commands = 'transcoder:commands';
    case State = 'transcoder:state';
    case Quality = 'transcoder:quality';
}