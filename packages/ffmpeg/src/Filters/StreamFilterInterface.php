<?php

namespace Baander\Ffmpeg\Filters;

use Baander\Ffmpeg\StreamInterface;
use FFMpeg\Filters\FilterInterface;

interface StreamFilterInterface extends FilterInterface
{
    /**
     * @param StreamInterface $stream
     * @return mixed
     */
    public function streamFilter(StreamInterface $stream): void;

    /**
     * @return array
     */
    public function apply(): array;
}