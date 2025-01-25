<?php

namespace Baander\Ffmpeg\Filters;

use Baander\Ffmpeg\StreamInterface;

abstract class StreamFilter implements StreamFilterInterface
{
    protected $filter = [];
    private $priority = 2;

    /**
     * Filter constructor.
     * @param StreamInterface $stream
     */
    public function __construct(StreamInterface $stream)
    {
        $this->streamFilter($stream);
    }

    /**
     * Applies the filter on the the stream media
     *
     * @return array An array of arguments
     */
    public function apply(): array
    {
        return $this->getFilter();
    }

    /**
     * @return array
     */
    public function getFilter(): array
    {
        return $this->filter;
    }

    /**
     * Returns the priority of the filter.
     *
     * @return integer
     */
    public function getPriority(): int
    {
        return $this->priority;
    }
}