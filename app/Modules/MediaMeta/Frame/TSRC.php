<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TSRC frame - ISRC (International Standard Recording Code).
 *
 * The 'ISRC' frame should contain the International Standard Recording Code (ISRC) (12 characters).
 */
class TSRC extends TextFrame
{
    /**
     * Constructs the TSRC frame with given parameters.
     */
    public function __construct(string $isrc = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TSRC', $isrc, $encoding);
    }

    /**
     * Returns the ISRC.
     */
    public function getIsrc(): string
    {
        return $this->getText();
    }

    /**
     * Sets the ISRC.
     */
    public function setIsrc(string $isrc): self
    {
        return $this->setText($isrc);
    }
}