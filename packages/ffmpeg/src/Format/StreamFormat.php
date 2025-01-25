<?php

namespace Baander\Ffmpeg\Format;

use Baander\Ffmpeg\Exception\InvalidArgumentException;
use FFMpeg\Format\Video\DefaultVideo;

abstract class StreamFormat extends DefaultVideo
{
    /**
     * @param int $kiloBitrate
     * @return DefaultVideo|void
     */
    public function setKiloBitrate($kiloBitrate)
    {
        throw new InvalidArgumentException("You can not set this option, use Representation instead");
    }

    /**
     * @param int $kiloBitrate
     * @return DefaultVideo|void
     */
    public function setAudioKiloBitrate($kiloBitrate)
    {
        throw new InvalidArgumentException("You can not set this option, use Representation instead");
    }
}