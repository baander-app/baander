<?php

namespace App\Modules\Metadata\MediaMeta\Frame;

use App\Modules\Metadata\MediaMeta\Encoding;

/**
 * TORY frame - Original release year.
 *
 * The 'Original release year' frame is intended for the year when the original recording was released.
 * The frame is represented as a numeric string in the YYYY format.
 */
class TORY extends TextFrame
{
    /**
     * Constructs the TORY frame with given parameters.
     */
    public function __construct(string $year = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TORY', $year, $encoding);
    }

    /**
     * Returns the original release year.
     */
    public function getYear(): string
    {
        return $this->getText();
    }

    /**
     * Sets the original release year.
     */
    public function setYear(string $year): self
    {
        return $this->setText($year);
    }
}