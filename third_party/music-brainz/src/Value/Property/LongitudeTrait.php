<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Longitude;

use function is_null;

/**
 * Provides a getter for a longitude.
 */
trait LongitudeTrait
{
    /**
     * The longitude
     *
     * @var Longitude
     */
    private Longitude $longitude;

    /**
     * Returns the longitude.
     *
     * @return Longitude
     */
    public function getLongitude(): Longitude
    {
        return $this->longitude;
    }

    /**
     * Sets the longitude by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLongitudeFromArray(array $input): void
    {
        $this->longitude = is_null($longitude = ArrayAccess::getFloat($input, 'longitude'))
            ? new Longitude()
            : new Longitude($longitude);
    }
}
