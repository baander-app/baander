<?php

namespace App\Modules\Transcoder;

class TranscoderConfig
{
    public function getLoggerName()
    {
        return 'transcoder';
    }

    public function getDefaultFormat()
    {
        return 'mp4';
    }
}