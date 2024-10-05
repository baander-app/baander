<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Place;

use function is_null;

/**
 * Provides a getter for a place.
 */
trait PlaceTrait
{
    /**
     * The place number
     *
     * @var Place
     */
    public Place $place;

    /**
     * Returns the place.
     *
     * @return Place
     */
    public function getPlace(): Place
    {
        return $this->place;
    }

    /**
     * Sets the place by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setPlaceFromArray(array $input): void
    {
        $this->place = is_null($place = ArrayAccess::getArray($input, 'place'))
            ? new Place()
            : new Place($place);
    }
}
