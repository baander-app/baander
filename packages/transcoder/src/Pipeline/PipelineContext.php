<?php

namespace Baander\Transcoder\Pipeline;

/**
 * @property string|null $outputDirectory
 * @property string|null $masterPlaylist
 * @property string|null $mediaFilePath
 * @property string|null $subtitles
 * @property string|null $subtitleMode
 */
class PipelineContext
{
    private array $context = [];

    public function __get(string $name)
    {
        return $this->context[$name] ?? null;
    }

    public function __set(string $name, $value): void
    {
        $this->context[$name] = $value;
    }

    public function basename()
    {
        return $this->mediaFilePath ? basename($this->mediaFilePath) : null;
    }
}