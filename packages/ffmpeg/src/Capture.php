<?php

namespace Baander\Ffmpeg;

use Baander\Ffmpeg\Exception\RuntimeException;

class Capture
{
    /**
     * @var string
     */
    private $video;
    /**
     * @var string|null
     */
    private $audio;
    /**
     * @var bool
     */
    private $screen;

    /**
     * Camera constructor.
     * @param string $video
     * @param string|null $audio
     * @param bool $screen
     */
    public function __construct(string $video, string $audio = null, $screen = false)
    {
        $this->video = $video;
        $this->audio = $audio;
        $this->screen = $screen;
    }

    /**
     * @return array
     */
    public function linux(): array
    {
        return [$this->video, ['f' => $this->screen ? 'x11grab' : 'v4l2']];
    }

    /**
     * @return array
     */
    public function windows(): array
    {
        $path = "video=$this->video";
        if (!is_null($this->audio)) {
            $path .= ":audio=$this->audio";
        }

        return [$path, ['f' => 'dshow']];
    }

    /**
     * @return array
     */
    public function osX(): array
    {
        return [$this->video, ['f' => 'avfoundation']];
    }

    /**
     * @throw Runtime exception
     */
    public function unknown()
    {
        throw new RuntimeException("Unknown operating system! It cannot run the camera on this platform");
    }

    /**
     * @return array
     */
    public function getOptions(): array
    {
        return call_user_func([$this, Utils::getOS()]);
    }
}