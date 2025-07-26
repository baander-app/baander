<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * TLEN frame - Length.
 *
 * The 'Length' frame contains the length of the audio file in milliseconds, represented as a numeric string.
 */
class TLEN extends TextFrame
{
    /**
     * Constructs the TLEN frame with given parameters.
     */
    public function __construct(string $length = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TLEN', $length, $encoding);
    }

    /**
     * Returns the length in milliseconds.
     */
    public function getLength(): string
    {
        return $this->getText();
    }

    /**
     * Sets the length in milliseconds.
     */
    public function setLength(string $length): self
    {
        return $this->setText($length);
    }
}