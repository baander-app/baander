<?php

namespace App\Modules\FFmpeg\Support;

use FFMpeg\FFProbe\DataMapping\Stream;
use App\Primitives\Text;

class StreamParser
{
    private Stream $stream;

    public function __construct(Stream $stream)
    {
        $this->stream = $stream;
    }

    public static function new(Stream $stream): StreamParser
    {
        return new static($stream);
    }

    public function getFrameRate(): ?string
    {
        $frameRate = trim(optional($this->stream)->get('avg_frame_rate'));

        if (!$frameRate || Text::endsWith($frameRate, '/0')) {
            return null;
        }

        if (Text::contains($frameRate, '/')) {
            [$numerator, $denominator] = explode('/', $frameRate);

            $frameRate = $numerator / $denominator;
        }

        return $frameRate ? number_format($frameRate, 3, '.', '') : null;
    }
}
