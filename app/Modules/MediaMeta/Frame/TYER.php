<?php

namespace App\Modules\MediaMeta\Frame;

use App\Modules\MediaMeta\Encoding;

/**
 * TYER frame - Year.
 *
 * The 'Year' frame is a numeric string with the year of the recording.
 * This frame is always four characters long (until the year 10000).
 */
class TYER extends TextFrame
{
    /**
     * Constructs the TYER frame with given parameters.
     *
     * @param string $year The year
     * @param int $encoding The text encoding
     */
    public function __construct(string $year = '', int $encoding = Encoding::UTF8)
    {
        parent::__construct('TYER', $year, $encoding);
    }

    /**
     * Returns the year.
     *
     * @return string
     */
    public function getYear(): string
    {
        return $this->getText();
    }

    /**
     * Sets the year as an integer.
     *
     * @param int $year The year
     * @return self
     */
    public function setYearInt(int $year): self
    {
        return $this->setYear(sprintf('%04d', $year));
    }

    /**
     * Sets the year.
     *
     * @param string $year The year
     * @return self
     */
    public function setYear(string $year): self
    {
        // Ensure the year is a valid format (4 digits)
        if (preg_match('/^\d{4}$/', $year)) {
            return $this->setText($year);
        }

        return $this->setText('');
    }
}