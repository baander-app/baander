<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * TPE2 frame - Band/orchestra/accompaniment.
 *
 * The 'Band/orchestra/accompaniment' frame is used for additional information about the performers in the recording.
 */
class TPE2 extends TextFrame
{
    /**
     * Constructs the TPE2 frame with given parameters.
     */
    public function __construct(string $band = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TPE2', $band, $encoding);
    }

    /**
     * Returns the band/orchestra/accompaniment.
     */
    public function getBand(): string
    {
        return $this->getText();
    }

    /**
     * Sets the band/orchestra/accompaniment.
     */
    public function setBand(string $band): self
    {
        return $this->setText($band);
    }
}