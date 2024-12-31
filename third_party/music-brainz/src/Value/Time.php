<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A day time
 */
class Time implements Value
{
    /**
     * The hour
     *
     * @var null|int
     */
    private $hour;

    /**
     * The minute
     *
     * @var null|int
     */
    private $minute;

    /**
     * The time of day as string, formatted like 00:00, ..., 23:59
     *
     * @var string
     */
    private $time = '';

    /**
     * Constructs a time of day.
     *
     * @param string $time A time of day as string, formatted like 00:00, ..., 23:59
     */
    public function __construct(string $time = '')
    {
        if (preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $this->hour   = (int) substr($time, 0, 2);
            $this->minute = (int) substr($time, 3, 2);
            $this->time   = $time;
        }
    }

    /**
     * Returns the hour.
     *
     * @return null|int
     */
    public function getHour(): ?int
    {
        return $this->hour;
    }

    /**
     * Returns the minute.
     *
     * @return null|int
     */
    public function getMinute(): ?int
    {
        return $this->minute;
    }

    /**
     * Returns the time of day as string, formatted like 00:00, ..., 23:59.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->time;
    }
}
