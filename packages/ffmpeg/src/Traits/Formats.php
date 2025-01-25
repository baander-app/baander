<?php

namespace Baander\Ffmpeg\Traits;

use Baander\Ffmpeg\Exception\InvalidArgumentException;
use Baander\Ffmpeg\Format\HEVC;
use Baander\Ffmpeg\Format\StreamFormat;
use Baander\Ffmpeg\Format\VP9;
use Baander\Ffmpeg\Format\X264;
use FFMpeg\Format\VideoInterface;

trait Formats
{
    /** @var VideoInterface | \FFMpeg\Format\Video\DefaultVideo */
    protected $format;

    /**
     * @param string $video_codec
     * @param string|null $audio_codec
     * @param bool $default_init_opts
     * @return $this
     */
    public function x264(string $video_codec = 'libx264', string $audio_codec = 'aac', bool $default_init_opts = true)
    {
        $this->setFormat(new X264($video_codec, $audio_codec, $default_init_opts));
        return $this;
    }

    /**
     * @param string $video_codec
     * @param string|null $audio_codec
     * @param bool $default_init_opts
     * @return $this
     */
    public function hevc(string $video_codec = 'libx265', string $audio_codec = 'aac', bool $default_init_opts = true)
    {
        $this->setFormat(new HEVC($video_codec, $audio_codec, $default_init_opts));
        return $this;
    }

    /**
     * @param string $video_codec
     * @param string|null $audio_codec
     * @param bool $default_init_opts
     * @return $this
     */
    public function vp9(string $video_codec = 'libvpx-vp9', string $audio_codec = 'aac', bool $default_init_opts = true)
    {
        $this->setFormat(new VP9($video_codec, $audio_codec, $default_init_opts));
        return $this;
    }

    /**
     * @return VideoInterface
     */
    public function getFormat(): VideoInterface
    {
        return $this->format;
    }

    /**
     * @param mixed $format
     * @return $this
     * @throws InvalidArgumentException
     */
    public function setFormat(StreamFormat $format)
    {
        $this->format = $format;
        return $this;
    }
}