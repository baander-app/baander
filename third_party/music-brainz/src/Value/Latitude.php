<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * A latitude
 *
 * @link https://musicbrainz.org/doc/Place
 */
class Latitude implements Value
{
    /**
     * The latitude
     *
     * @var null|float
     */
    private $latitude;

    /**
     * Constructs a latitude.
     *
     * @param float $latitude The latitude
     */
    public function __construct(float $latitude = null)
    {
        $this->latitude = $latitude;
    }

    /**
     * Returns the latitude as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->latitude;
    }
}
