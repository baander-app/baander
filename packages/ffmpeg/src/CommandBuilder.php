<?php

namespace Baander\Ffmpeg;

use FFMpeg\Format\VideoInterface;

class CommandBuilder
{
    /** @var Media */
    private $media;

    /** @var \FFMpeg\Filters\FiltersCollection */
    private $filters;

    /** @var \FFMpeg\Driver\FFMpegDriver */
    private $driver;

    /** @var VideoInterface */
    private $format;

    /**
     * CommandBuilder constructor.
     * @param Media $media
     * @param VideoInterface $format
     */
    public function __construct(Media $media, VideoInterface $format)
    {
        $this->media = $media;
        $this->filters = $this->media->getFiltersCollection();
        $this->driver = $this->media->getFFMpegDriver();
        $this->format = $format;
    }

    /**
     * @param VideoInterface $format
     * @param string $path
     * @return array
     * @TODO: optimize this function
     */
    public function build(VideoInterface $format, string $path): array
    {
        $commands = [];

        foreach ($this->filters as $filter) {
            $commands = array_merge($this->getInputOptions(), $filter->apply($this->media->baseMedia(), $format));
        }

        if ($this->driver->getConfiguration()->has('ffmpeg.threads')) {
            $commands = array_merge($commands, ['-threads', $this->driver->getConfiguration()->get('ffmpeg.threads')]);
        }

        $commands[] = $path;

        return $commands;
    }

    /**
     * @return array
     */
    private function getInputOptions(): array
    {
        $path = $this->media->getPathfile();
        $input_options = Utils::arrayToFFmpegOpt($this->media->getInputOptions());

        return array_merge($input_options, $this->format->getInitialParameters() ?? [], ['-y', '-i', $path]);
    }
}