<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TDAT frame - Date.
 *
 * The 'Date' frame is a numeric string in the DDMM format containing the date for the recording.
 * This field is always four characters long.
 */
class TDAT extends TextFrame
{
    /**
     * Constructs the TDAT frame with given parameters.
     */
    public function __construct(string $date = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TDAT', $date, $encoding);
    }

    /**
     * Returns the date.
     */
    public function getDate(): string
    {
        return $this->getText();
    }

    /**
     * Sets the date.
     */
    public function setDate(string $date): self
    {
        return $this->setText($date);
    }
}