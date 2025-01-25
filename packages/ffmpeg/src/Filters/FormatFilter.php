<?php

namespace Baander\Ffmpeg\Filters;

use Baander\Ffmpeg\Utils;
use FFMpeg\Format\VideoInterface;

abstract class FormatFilter extends StreamFilter
{
    /**
     * @param VideoInterface $format
     * @return array
     */
    protected function getFormatOptions(VideoInterface $format): array
    {
        $basic = Utils::arrayToFFmpegOpt([
            'c:v' => $format->getVideoCodec(),
            'c:a' => $format->getAudioCodec(),
        ]);

        $options = Utils::arrayToFFmpegOpt(
            array_merge($format->getAdditionalParameters() ?? []),
        );

        return array_merge($basic, $options);
    }
}