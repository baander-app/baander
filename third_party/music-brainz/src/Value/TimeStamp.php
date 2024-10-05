<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use DateTime;
use MusicBrainz\Value;

/**
 * A day time
 */
class TimeStamp implements Value
{
    /**
     * The time of day as string, formatted like 00:00, ..., 23:59
     *
     * @var null|DateTime
     */
    private $dateTime;

    /**
     * Constructs a time of day.
     *
     * @param string $time A time of day as string, formatted like 00:00, ..., 23:59
     */
    public function __construct(string $time = '')
    {
        if (
            preg_match(
                '/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])T(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9].[0-9]{1,3}Z$/',
                $time
            )
        ) {
            /**
             * - 'T' has a meaning in the time format given to createFromFormat(). So we replace it by '-' with strtr().
             * - \DateTime::format() doesn't work, if the DateTime object was created with milliseconds. So we remove
             * them with substr()
             */
            $dateTime = DateTime::createFromFormat(
                'Y-m-d-H:i:s',
                substr(strtr($time, 'T', '-'), 0, 19)
            );

            $this->dateTime = (false === $dateTime) ? null : $dateTime;
        }
    }

    /**
     * Returns the DateTime object.
     *
     * @return null|DateTime
     */
    public function getDateTime(): ?DateTime
    {
        return $this->dateTime;
    }

    /**
     * Returns the time of day as string, formatted like "2017/07/01 09:05".
     *
     * @return string
     */
    public function __toString(): string
    {
        return (null !== $this->dateTime)
            ? $this->dateTime->format('Y/m/d H:i:s')
            : '';
    }
}
