<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\PlaceType;

use function is_null;

/**
 * Provides a getter for the type of place.
 */
trait PlaceTypeTrait
{
    /**
     * A type of place
     *
     * @var PlaceType
     */
    private PlaceType $placeType;

    /**
     * Returns type of place.
     *
     * @return PlaceType
     */
    public function getPlaceType(): PlaceType
    {
        return $this->placeType;
    }

    /**
     * Sets the type of place by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     *
     * @return void
     */
    private function setPlaceTypeFromArray(array $input): void
    {
        $this->placeType = is_null($placeType = ArrayAccess::getString($input, 'type'))
            ? new PlaceType()
            : new PlaceType($placeType);
    }
}
