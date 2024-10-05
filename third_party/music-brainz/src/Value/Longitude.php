<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A longitude
 *
 * @link https://musicbrainz.org/doc/Place
 */
class Longitude implements Value
{
    /**
     * The longitude
     *
     * @var null|float
     */
    private $longitude;

    /**
     * Constructs a longitude.
     *
     * @param float $longitude The longitude
     */
    public function __construct(float $longitude = null)
    {
        $this->longitude = $longitude;
    }

    /**
     * Returns the longitude as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->longitude;
    }
}
