<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * The latitude and longitude describe the location of the place using geographic coordinates.
 *
 * @link https://musicbrainz.org/doc/Place
 */
class Coordinates implements Value
{
    use Property\LatitudeTrait;
    use Property\LongitudeTrait;

    /**
     * Constructs the coordinates.
     *
     * @param array $coordinates The coordinates
     */
    public function __construct(array $coordinates = [])
    {
        $this->setLatitudeFromArray($coordinates);
        $this->setLongitudeFromArray($coordinates);
    }

    /**
     * Returns the coordinates as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (!empty((string) $this->getLatitude()) && !empty((string) $this->getLongitude()))
            ? $this->getLatitude() . ', ' . $this->getLongitude()
            : '';
    }
}
