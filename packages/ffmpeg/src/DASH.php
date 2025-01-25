<?php

namespace Baander\Ffmpeg;

use Baander\Ffmpeg\Filters\DASHFilter;
use Baander\Ffmpeg\Filters\StreamFilterInterface;
use FFMpeg\Format\VideoInterface;

class DASH extends Streaming
{
    /** @var string */
    private $adaption;

    /** @var string */
    private $seg_duration = 10;

    /** @var bool */
    private $generate_hls_playlist = false;

    /**
     * @return mixed
     */
    public function getAdaption()
    {
        return $this->adaption;
    }

    /**
     * @param mixed $adaption
     * @return DASH
     */
    public function setAdaption(string $adaption): DASH
    {
        $this->adaption = $adaption;
        return $this;
    }

    /**
     * @return string
     */
    public function getSegDuration(): string
    {
        return $this->seg_duration;
    }

    /**
     * @param string $seg_duration
     * @return DASH
     */
    public function setSegDuration(string $seg_duration): DASH
    {
        $this->seg_duration = $seg_duration;
        return $this;
    }

    /**
     * @param bool $generate_hls_playlist
     * @return DASH
     */
    public function generateHlsPlaylist(bool $generate_hls_playlist = true): DASH
    {
        $this->generate_hls_playlist = $generate_hls_playlist;
        return $this;
    }

    /**
     * @return bool
     */
    public function isGenerateHlsPlaylist(): bool
    {
        return $this->generate_hls_playlist;
    }

    public function getFormat(): VideoInterface
    {
        // TODO: Implement getFormat() method.
    }

    /**
     * @return DASHFilter
     */
    protected function getFilter(): StreamFilterInterface
    {
        return new DASHFilter($this);
    }

    /**
     * @return string
     */
    protected function getPath(): string
    {
        return implode(".", [$this->getFilePath(), "mpd"]);
    }
}