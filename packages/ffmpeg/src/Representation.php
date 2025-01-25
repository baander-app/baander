<?php


namespace Baander\Ffmpeg;

use Baander\Ffmpeg\Exception\InvalidArgumentException;
use FFMpeg\Coordinate\AspectRatio;
use FFMpeg\Coordinate\Dimension;

class Representation implements RepresentationInterface
{
    /** @var int $kiloBitrate video kilo bitrate */
    private $kiloBitrate;

    /** @var int $audioKiloBitrate audio kilo bitrate */
    private $audioKiloBitrate;

    /** @var Dimension $size size of representation */
    private $size;

    /** @var array $hls_stream_info hls stream info */
    private $hls_stream_info = [];

    /**
     * @return string | null
     */
    public function size2string(): ?string
    {
        return !is_null($this->size) ? implode("x", [$this->getWidth(), $this->getHeight()]) : null;
    }

    /**
     * @return int
     */
    public function getWidth(): int
    {
        return $this->size->getWidth();
    }

    /**
     * @return int
     */
    public function getHeight(): int
    {
        return $this->size->getHeight();
    }

    /**
     * @param $width
     * @param $height
     * @return Representation
     * @throws InvalidArgumentException
     */
    public function setResize(int $width, int $height): Representation
    {
        $this->setSize(new Dimension($width, $height));
        return $this;
    }

    /**
     * @return int
     */
    public function getKiloBitrate(): int
    {
        return $this->kiloBitrate;
    }

    /**
     * Sets the video kiloBitrate value.
     *
     * @param integer $kiloBitrate
     * @return Representation
     * @throws InvalidArgumentException
     */
    public function setKiloBitrate(int $kiloBitrate): Representation
    {
        if ($kiloBitrate < 1) {
            throw new InvalidArgumentException('Invalid kilo bit rate value');
        }

        $this->kiloBitrate = (int)$kiloBitrate;
        return $this;
    }

    /**
     * @return int|null
     */
    public function getAudioKiloBitrate()
    {
        return $this->audioKiloBitrate;
    }

    /**
     * Sets the video kiloBitrate value.
     *
     * @param integer $audioKiloBitrate
     * @return Representation
     * @throws InvalidArgumentException
     */
    public function setAudioKiloBitrate(int $audioKiloBitrate): Representation
    {
        $this->audioKiloBitrate = $audioKiloBitrate;
        return $this;
    }

    /**
     * @return AspectRatio
     */
    public function getRatio(): AspectRatio
    {
        return $this->size->getRatio();
    }

    /**
     * @return array
     */
    public function getHlsStreamInfo(): array
    {
        return $this->hls_stream_info;
    }

    /**
     * @param array $hls_stream_info
     * @return Representation
     */
    public function setHlsStreamInfo(array $hls_stream_info): Representation
    {
        $this->hls_stream_info = $hls_stream_info;
        return $this;
    }

    /**
     * @return Dimension
     */
    public function getSize(): Dimension
    {
        return $this->size;
    }

    /**
     * @param Dimension $size
     * @return Representation
     */
    public function setSize(Dimension $size): Representation
    {
        $this->size = $size;
        return $this;
    }
}