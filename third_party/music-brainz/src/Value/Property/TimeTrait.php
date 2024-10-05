<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Time;

use function is_null;

/**
 * Provides a getter for the time of day.
 */
trait TimeTrait
{
    /**
     * The time of day
     *
     * @var Time
     */
    public Time $time;

    /**
     * Returns the time of day.
     *
     * @return Time
     */
    public function getTime(): Time
    {
        return $this->time;
    }

    /**
     * Sets the time by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setTimeFromArray(array $input): void
    {
        $this->time = is_null($time = ArrayAccess::getString($input, 'time'))
            ? new Time()
            : new Time($time);
    }
}
