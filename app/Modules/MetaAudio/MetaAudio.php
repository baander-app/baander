<?php

namespace App\Modules\MetaAudio;

use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\StreamCollection;
use Illuminate\Support\Str;
use SplFileInfo;

class MetaAudio
{
    private FFMpeg $ffmpeg;
    private StreamCollection|null $streamCollection = null;


    public function __construct(public SplFileInfo $file)
    {
        $this->ffmpeg = FFMpeg::create();
    }

    public function isAudioFile(): bool
    {
        return Str::startsWith($this->mimeType(), 'audio/');
    }

    public function probeLength()
    {
        $stream = $this->getStreams()->first();

        if (!$stream) {
            return 0;
        }

        return (float)$stream->get('duration');
    }

    public function mimeType(): string
    {
        return \File::mimeType($this->file->getRealPath());
    }

    private function getStreams()
    {
        if ($this->streamCollection === null) {
            $this->streamCollection = $this->ffmpeg->getFFProbe()->streams($this->file->getRealPath());
        }

        return $this->streamCollection;
    }
}
