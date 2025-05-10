<?php

namespace Baander\FFMpeg\Exporters;

use FFMpeg\Format\FormatInterface;
use Baander\FFMpeg\FFMpeg\AdvancedOutputMapping;
use Baander\FFMpeg\Filesystem\Media;

trait HandlesAdvancedMedia
{
    /**
     * @var \Illuminate\Support\Collection
     */
    protected $maps;

    public function addFormatOutputMapping(FormatInterface $format, Media $output, array $outs, $forceDisableAudio = false, $forceDisableVideo = false)
    {
        $this->maps->push(
            new AdvancedOutputMapping($outs, $format, $output, $forceDisableAudio, $forceDisableVideo)
        );

        return $this;
    }
}
