<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Latitude;

use function is_null;

/**
 * Provides a getter for a latitude.
 */
trait LatitudeTrait
{
    /**
     * The latitude
     *
     * @var Latitude
     */
    private Latitude  $latitude;

    /**
     * Returns the latitude.
     *
     * @return Latitude
     */
    public function getLatitude(): Latitude
    {
        return $this->latitude;
    }

    /**
     * Sets the latitude by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setLatitudeFromArray(array $input): void
    {
        $this->latitude = is_null($latitude = ArrayAccess::getFloat($input, 'latitude'))
            ? new Latitude()
            : new Latitude($latitude);
    }
}
