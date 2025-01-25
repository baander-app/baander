<?php

namespace Baander\Ffmpeg;

use Baander\Ffmpeg\Filters\StreamFilterInterface;
use Baander\Ffmpeg\Filters\StreamToFileFilter;

class StreamToFile extends Stream
{
    /**
     * @var array
     */
    private $params = [];

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param array $params
     * @return StreamToFile
     */
    public function setParams(array $params): StreamToFile
    {
        $this->params = $params;
        return $this;
    }

    /**
     * @return string
     */
    protected function getPath(): string
    {
        return implode(".", [$this->getFilePath(), $this->pathInfo(PATHINFO_EXTENSION) ?? "mp4"]);
    }

    /**
     * @return StreamToFileFilter
     */
    protected function getFilter(): StreamFilterInterface
    {
        return new StreamToFileFilter($this);
    }
}