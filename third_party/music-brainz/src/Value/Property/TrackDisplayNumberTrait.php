<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\TrackDisplayNumber;

use function is_null;

/**
 * Provides a getter for a track number.
 */
trait TrackDisplayNumberTrait
{
    /**
     * The displayed track number
     *
     * @var TrackDisplayNumber
     */
    public TrackDisplayNumber $trackDisplayNumber;

    /**
     * Returns the displayed track number.
     *
     * @return TrackDisplayNumber
     */
    public function getTrackDisplayNumber(): TrackDisplayNumber
    {
        return $this->trackDisplayNumber;
    }

    /**
     * Sets the displayed track number by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setTrackDisplayNumberFromArray(array $input): void
    {
        $this->trackDisplayNumber = is_null($trackDisplayNumber = ArrayAccess::getString($input, 'number'))
            ? new TrackDisplayNumber()
            : new TrackDisplayNumber($trackDisplayNumber);
    }
}
