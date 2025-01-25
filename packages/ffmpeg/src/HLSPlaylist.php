<?php

namespace Baander\Ffmpeg;

use FFMpeg\Exception\ExceptionInterface;

class HLSPlaylist
{
    private const int DEFAULT_AUDIO_BITRATE = 0;
    /** @var HLS */
    private $hls; //131072;

    /**
     * HLSPlaylist constructor.
     * @param HLS $hls
     */
    public function __construct(HLS $hls)
    {
        $this->hls = $hls;
    }

    /**
     * @param string $filename
     * @param array $description
     */
    public function save(string $filename, array $description): void
    {
        File::put($filename, $this->contents(($description)));
    }

    /**
     * @param array $description
     * @return string
     */
    private function contents(array $description): string
    {
        $content = array_merge(["#EXTM3U", $this->getVersion()], $description);

        foreach ($this->hls->getRepresentations() as $rep) {
            array_push($content, $this->streamInfo($rep), $this->segmentPath($rep));
        }

        return implode(PHP_EOL, $content);
    }

    /**
     * @return string
     */
    private function getVersion(): string
    {
        $version = $this->hls->getHlsSegmentType() === "fmp4" ? 7 : 3;
        return "#EXT-X-VERSION:" . $version;
    }

    /**
     * @param Representation $rep
     * @return string
     */
    private function streamInfo(Representation $rep): string
    {
        $tag = '#EXT-X-STREAM-INF:';
        $params = array_merge(
            [
                "BANDWIDTH"  => $rep->getKiloBitrate() * 1024 + $this->getAudioBitrate($rep),
                "RESOLUTION" => $rep->size2string(),
                "NAME"       => "\"" . $rep->getHeight() . "\"",
            ],
            $rep->getHlsStreamInfo(),
        );
        Utils::concatKeyValue($params, "=");

        return $tag . implode(",", $params);
    }

    /**
     * @param Representation $rep
     * @return int
     */
    private function getAudioBitrate(Representation $rep): int
    {
        return $rep->getAudioKiloBitrate() ? $rep->getAudioKiloBitrate() * 1024 : $this->getOriginalAudioBitrate();
    }

    /**
     * @return int
     */
    private function getOriginalAudioBitrate(): int
    {
        try {
            $audios = $this->hls->getMedia()->getStreams()->audios();

            if (!$audios->count()) {
                return static::DEFAULT_AUDIO_BITRATE;
            }

            return $audios->first()->get('bit_rate', static::DEFAULT_AUDIO_BITRATE);
        } catch (ExceptionInterface $e) {
            return static::DEFAULT_AUDIO_BITRATE;
        }
    }

    /**
     * @param Representation $rep
     * @return string
     */
    private function segmentPath(Representation $rep): string
    {
        return $this->hls->pathInfo(PATHINFO_FILENAME) . "_" . $rep->getHeight() . "p.m3u8";
    }
}