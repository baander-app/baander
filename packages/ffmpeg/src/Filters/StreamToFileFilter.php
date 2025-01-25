<?php

namespace Baander\Ffmpeg\Filters;

use Baander\Ffmpeg\StreamInterface;

class StreamToFileFilter extends FormatFilter
{

    /**
     * @param $media
     * @return mixed
     */
    public function streamFilter(StreamInterface $media): void
    {
        $this->filter = array_merge(
            $this->getFormatOptions($media->getFormat()),
            $media->getParams(),
        );
    }
}