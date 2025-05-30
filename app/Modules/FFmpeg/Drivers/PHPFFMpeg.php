<?php

namespace App\Modules\FFmpeg\Drivers;

use Alchemy\BinaryDriver\Listeners\ListenerInterface;
use Exception;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Driver\FFMpegDriver;
use FFMpeg\FFMpeg;
use FFMpeg\Media\{AbstractMediaType, AdvancedMedia as BaseAdvancedMedia, Audio, Concat, Frame, Video};
use Illuminate\Support\{Arr, Collection, Traits\ForwardsCalls};
use App\Modules\FFmpeg\FFMpeg\{AdvancedMedia, AudioMedia, FFProbe, VideoMedia};
use App\Modules\FFmpeg\Filesystem\MediaCollection;

/**
 * @mixin \FFMpeg\Media\AbstractMediaType
 */
class PHPFFMpeg
{
    use ForwardsCalls, InteractsWithFilters, InteractsWithMediaStreams;

    /**
     * @var \FFMpeg\FFMpeg
     */
    private $ffmpeg;

    /**
     * @var \App\Modules\FFmpeg\Filesystem\MediaCollection
     */
    private $mediaCollection;

    /**
     * @var boolean
     */
    private $forceAdvanced = false;

    /**
     * @var \FFMpeg\Media\AbstractMediaType
     */
    private $media;

    /**
     * Callbacks that should be called just before the
     * underlying library hits the save method.
     */
    private array $beforeSavingCallbacks = [];

    public function __construct(FFMpeg $ffmpeg)
    {
        $this->ffmpeg = $ffmpeg;
        $this->pendingComplexFilters = new Collection();
    }

    /**
     * Returns a fresh instance of itself with only the underlying FFMpeg instance.
     */
    public function fresh(): self
    {
        return new static($this->ffmpeg);
    }

    public function get(): AbstractMediaType
    {
        return $this->media;
    }

    private function isAdvancedMedia(): bool
    {
        return $this->get() instanceof BaseAdvancedMedia;
    }

    public function isFrame(): bool
    {
        return $this->get() instanceof Frame;
    }

    public function isConcat(): bool
    {
        return $this->get() instanceof Concat;
    }

    public function isVideo(): bool
    {
        return $this->get() instanceof Video;
    }

    public function getMediaCollection(): MediaCollection
    {
        return $this->mediaCollection;
    }

    /**
     * Opens the MediaCollection if it's not been instanciated yet.
     */
    public function open(MediaCollection $mediaCollection): self
    {
        if ($this->media) {
            return $this;
        }

        $this->mediaCollection = $mediaCollection;

        if ($mediaCollection->count() === 1 && !$this->forceAdvanced) {
            $media = Arr::first($mediaCollection->collection());

            $this->ffmpeg->setFFProbe(
                FFProbe::make($this->ffmpeg->getFFProbe())->setMedia($media),
            );

            $ffmpegMedia = $this->ffmpeg->open($media->getLocalPath());

            // this should be refactored to a factory...
            if ($ffmpegMedia instanceof Video) {
                $this->media = VideoMedia::make($ffmpegMedia);
            } else if ($ffmpegMedia instanceof Audio) {
                $this->media = AudioMedia::make($ffmpegMedia);
            } else {
                $this->media = $ffmpegMedia;
            }

            if (method_exists($this->media, 'setHeaders')) {
                $this->media->setHeaders(Arr::first($mediaCollection->getHeaders()) ?: []);
            }
        } else {
            $ffmpegMedia = $this->ffmpeg->openAdvanced($mediaCollection->getLocalPaths());

            $this->media = AdvancedMedia::make($ffmpegMedia)->setHeaders($mediaCollection->getHeaders());
        }

        return $this;
    }

    /**
     * @throws Exception
     */
    public function frame(TimeCode $timeCode): static
    {
        if (!$this->isVideo()) {
            throw new Exception('Opened media is not a video file.');
        }

        $this->media = $this->media->frame($timeCode);

        return $this;
    }

    public function concatWithoutTranscoding(): static
    {
        $localPaths = $this->mediaCollection->getLocalPaths();

        $this->media = $this->ffmpeg->open(Arr::first($localPaths))
            ->concat($localPaths);

        return $this;
    }

    /**
     * Force 'openAdvanced' when opening the MediaCollection
     */
    public function openAdvanced(MediaCollection $mediaCollection): self
    {
        $this->forceAdvanced = true;

        return $this->open($mediaCollection);
    }

    /**
     * Returns the FFMpegDriver of the underlying library.
     *
     * @return \FFMpeg\Driver\FFMpegDriver
     */
    private function getFFMpegDriver(): FFMpegDriver
    {
        return $this->get()->getFFMpegDriver();
    }

    /**
     * Add a Listener to the underlying library.
     *
     * @param \Alchemy\BinaryDriver\Listeners\ListenerInterface $listener
     * @return self
     */
    public function addListener(ListenerInterface $listener): self
    {
        $this->getFFMpegDriver()->listen($listener);

        return $this;
    }

    /**
     * Remove the Listener from the underlying library.
     *
     * @param \Alchemy\BinaryDriver\Listeners\ListenerInterface $listener
     * @return self
     */
    public function removeListener(ListenerInterface $listener): self
    {
        $this->getFFMpegDriver()->unlisten($listener);

        return $this;
    }

    /**
     * Adds a callable to the callbacks array.
     *
     * @param callable $callback
     * @return self
     */
    public function beforeSaving(callable $callback): self
    {
        $this->beforeSavingCallbacks[] = $callback;

        return $this;
    }

    /**
     * Set the callbacks on the Media.
     *
     * @return self
     */
    public function applyBeforeSavingCallbacks(): self
    {
        $media = $this->get();

        if (method_exists($media, 'setBeforeSavingCallbacks')) {
            $media->setBeforeSavingCallbacks($this->beforeSavingCallbacks);
        }

        return $this;
    }

    /**
     * Add an event handler to the underlying library.
     *
     * @param string $event
     * @param callable $callback
     * @return self
     */
    public function onEvent(string $event, callable $callback): self
    {
        $this->getFFMpegDriver()->on($event, $callback);

        return $this;
    }

    /**
     * Returns the underlying media object itself.
     */
    public function __invoke(): AbstractMediaType
    {
        return $this->get();
    }

    /**
     * Forwards the call to the underling media object and returns the result
     * if it's something different from the media object itself.
     */
    public function __call($method, $arguments)
    {
        $result = $this->forwardCallTo($media = $this->get(), $method, $arguments);

        return ($result === $media) ? $this : $result;
    }
}
