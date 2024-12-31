<?php

namespace App\Packages\FFmpeg\Exporters;

use FFMpeg\Format\FormatInterface;
use App\Packages\FFmpeg\FFMpeg\AdvancedOutputMapping;
use App\Packages\FFmpeg\Filesystem\Media;

trait HandlesAdvancedMedia
{
    /**
     * @var \Illuminate\Support\Collection
     */
    protected $maps;

    public function addFormatOutputMapping(FormatInterface $format, Media $output, array $outs, $forceDisableAudio = false, $forceDisableVideo = false)
    {
        $this->maps->push(
            new AdvancedOutputMapping($outs, $format, $output, $forceDisableAudio, $forceDisableVideo),
        );

        return $this;
    }
}
