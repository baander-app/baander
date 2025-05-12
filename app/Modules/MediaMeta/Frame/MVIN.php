<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * MVIN frame - Movement number.
 *
 * The 'Movement number' frame is a numeric string containing the movement number of the
 * audio-file in a larger work. This may be extended with a "/" character and a numeric string
 * containing the total number of movements in the work. E.g. "2/5".
 */
class MVIN extends TextFrame
{
    /**
     * Constructs the MVIN frame with given parameters.
     */
    public function __construct(string $movement = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('MVIN', $movement, $encoding);
    }

    /**
     * Returns the movement number.
     */
    public function getMovement(): string
    {
        return $this->getText();
    }

    /**
     * Sets the movement number.
     */
    public function setMovement(string $movement): self
    {
        return $this->setText($movement);
    }
}