<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Coordinates;

use function is_null;

/**
 * Provides a getter for the coordinates.
 */
trait CoordinatesTrait
{
    /**
     * The coordinates
     *
     * @var Coordinates
     */
    private Coordinates $coordinates;

    /**
     * Returns the coordinates.
     *
     * @return Coordinates
     */
    public function getCoordinates(): Coordinates
    {
        return $this->coordinates;
    }

    /**
     * Sets the coordinates by extracting them from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setCoordinatesFromArray(array $input): void
    {
        $this->coordinates = is_null($coordinates = ArrayAccess::getArray($input, 'coordinates'))
            ? new Coordinates()
            : new Coordinates($coordinates);
    }
}
