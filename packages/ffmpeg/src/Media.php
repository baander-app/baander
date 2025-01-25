<?php


namespace Baander\Ffmpeg;

use FFMpeg\Media\MediaTypeInterface;
use FFMpeg\Media\Video;

/** @mixin  Video */
class Media
{
    /** @var Video */
    private $media;

    /** @var bool */
    private $is_tmp;

    /** @var array */
    private $input_options;

    /**
     * Media constructor.
     * @param MediaTypeInterface $media
     * @param bool $is_tmp
     * @param array $input_options
     */
    public function __construct(MediaTypeInterface $media, bool $is_tmp, array $input_options = [])
    {
        $this->media = $media;
        $this->is_tmp = $is_tmp;
        $this->input_options = $input_options;
    }

    /**
     * @return DASH
     */
    public function dash(): DASH
    {
        return new DASH($this);
    }

    /**
     * @return HLS
     */
    public function hls(): HLS
    {
        return new HLS($this);
    }

    /**
     * @return StreamToFile
     */
    public function stream2file(): StreamToFile
    {
        return new StreamToFile($this);
    }

    /**
     * @return bool
     */
    public function isTmp(): bool
    {
        return $this->is_tmp;
    }

    /**
     * @return Video | \FFMpeg\Media\Audio
     */
    public function baseMedia(): MediaTypeInterface
    {
        return $this->media;
    }

    /**
     * @param $method
     * @param $parameters
     * @return Media | Video
     */
    public function __call($method, $parameters)
    {
        return $this->isInstanceofArgument(
            call_user_func_array([$this->media, $method], $parameters),
        );
    }

    /**
     * @param $argument
     * @return Media | Video
     */
    private function isInstanceofArgument($argument)
    {
        return ($argument instanceof $this->media) ? $this : $argument;
    }

    /**
     * @return array
     */
    public function getInputOptions(): array
    {
        return $this->input_options;
    }
}