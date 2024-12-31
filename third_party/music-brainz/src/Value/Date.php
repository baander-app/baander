<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use DateTime;
use MusicBrainz\Value;

/**
 * A date
 */
class Date implements Value
{
    /**
     * The original given date format
     * This value is necessary to interpret the date time object correctly. As the date time object always points to
     * an exact point of time, it will point to 2017/01/01 at 00:00 a. m. if constructed by the string "2017" that
     * intends to mean "sometime in 2017".
     *
     * @var null|string
     */
    private $originalFormat;

    /**
     * Date and time
     *
     * @var null|DateTime
     */
    private $dateTime;

    /**
     * Constructs a date
     *
     * @param string $date A date formatted as defined on top
     */
    public function __construct(string $date = '')
    {
        if (empty($date)) {
            return;
        }

        $this->originalFormat = $this->getFormat($date);

        if (null === $this->originalFormat) {
            return;
        }

        $dateTime = DateTime::createFromFormat('!' . $this->originalFormat, $date);

        $this->dateTime = ($dateTime && $dateTime->format($this->originalFormat) === $date)
            ? $dateTime
            : null;
    }

    /**
     * Returns the date time format by a given date string.
     * Supported formats are:
     * - Y-m-d: 2017-03-01
     * - Y-m: 2017-03
     * - Y: 2017
     *
     * @param string $date A date string
     *
     * @return null|string
     */
    private function getFormat(string $date): ?string
    {
        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/', $date)) {
            return 'Y-m-d';
        }

        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $date)) {
            return 'Y-m';
        }

        if (preg_match('/^[0-9]{4}$/', $date)) {
            return 'Y';
        }

        return null;
    }

    /**
     * Returns the date time object.
     *
     * @return null|DateTime
     */
    public function getDateTime(): ?DateTime
    {
        return $this->dateTime;
    }

    /**
     * Returns the original format.
     *
     * @return null|string
     */
    public function getOriginalFormat(): ?string
    {
        return $this->originalFormat;
    }

    /**
     * Returns the date in the originally given format.
     *
     * @return string
     */
    public function __toString(): string
    {
        return isset($this->dateTime) && isset($this->originalFormat)
            ? $this->dateTime->format($this->originalFormat)
            : '';
    }
}
