<?php

namespace Baander\Ffmpeg;

use Baander\Ffmpeg\Traits\Representations;

abstract class Streaming extends Stream
{
    use Representations;

    /** @var string */
    private $strict = "-2";

    /** @var array */
    private $additional_params = [];

    /**
     * Streaming constructor.
     * @param Media $media
     */
    public function __construct(Media $media)
    {
        $this->reps = new RepsCollection();
        parent::__construct($media);
    }

    /**
     * @return array
     */
    public function getAdditionalParams(): array
    {
        return $this->additional_params;
    }

    /**
     * @param array $additional_params
     * @return Stream
     */
    public function setAdditionalParams(array $additional_params)
    {
        $this->additional_params = $additional_params;
        return $this;
    }

    /**
     * @return string
     */
    public function getStrict(): string
    {
        return $this->strict;
    }

    /**
     * @param string $strict
     * @return Stream
     */
    public function setStrict(string $strict): Stream
    {
        $this->strict = $strict;
        return $this;
    }

}