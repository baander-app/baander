<?php

namespace Baander\Ffmpeg\Filters;

use Baander\Ffmpeg\DASH;
use Baander\Ffmpeg\Representation;
use Baander\Ffmpeg\StreamInterface;
use Baander\Ffmpeg\Utils;

class DASHFilter extends FormatFilter
{
    /** @var DASH */
    private $dash;

    /**
     * @param StreamInterface $dash
     */
    public function streamFilter(StreamInterface $dash): void
    {
        $this->dash = $dash;

        $this->filter = array_merge(
            $this->getFormatOptions($dash->getFormat()),
            $this->getArgs(),
        );
    }

    /**
     * @return array
     */
    private function getArgs(): array
    {
        return array_merge(
            $this->init(),
            $this->streams(),
            ['-strict', $this->dash->getStrict()],
        );
    }

    /**
     * @return array
     */
    private function init(): array
    {
        $name = $this->dash->pathInfo(PATHINFO_FILENAME);

        $init = [
            "use_timeline"   => 1,
            "use_template"   => 1,
            "init_seg_name"  => $name . '_init_$RepresentationID$.$ext$',
            "media_seg_name" => $name . '_chunk_$RepresentationID$_$Number%05d$.$ext$',
            "seg_duration"   => $this->dash->getSegDuration(),
            "hls_playlist"   => (int)$this->dash->isGenerateHlsPlaylist(),
            "f"              => "dash",
        ];

        return array_merge(
            Utils::arrayToFFmpegOpt($init),
            $this->getAdaptions(),
            Utils::arrayToFFmpegOpt($this->dash->getAdditionalParams()),
        );
    }

    /**
     * @return array
     */
    private function getAdaptions(): array
    {
        return $this->dash->getAdaption() ? ['-adaptation_sets', $this->dash->getAdaption()] : [];
    }

    /**
     * @return array
     */
    private function streams(): array
    {
        $streams = [];
        foreach ($this->dash->getRepresentations() as $key => $rep) {
            $streams = array_merge(
                $streams,
                Utils::arrayToFFmpegOpt([
                    'map'      => 0,
                    "s:v:$key" => $rep->size2string(),
                    "b:v:$key" => $rep->getKiloBitrate() . "k",
                ]),
                $this->getAudioBitrate($rep, $key),
            );
        }

        return $streams;
    }

    /**
     * @param Representation $rep
     * @param int $key
     * @return array
     */
    private function getAudioBitrate(Representation $rep, int $key): array
    {
        return $rep->getAudioKiloBitrate() ? ["-b:a:" . $key, $rep->getAudioKiloBitrate() . "k"] : [];
    }
}