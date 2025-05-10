<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TEXT frame - Lyricist/Text writer.
 *
 * The 'Lyricist/Text writer' frame is intended for the writer of the text or lyrics in the recording.
 */
class TEXT extends TextFrame
{
    /**
     * Constructs the TEXT frame with given parameters.
     */
    public function __construct(string $lyricist = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TEXT', $lyricist, $encoding);
    }

    /**
     * Returns the lyricist/text writer.
     */
    public function getLyricist(): string
    {
        return $this->getText();
    }

    /**
     * Sets the lyricist/text writer.
     */
    public function setLyricist(string $lyricist): self
    {
        return $this->setText($lyricist);
    }
}