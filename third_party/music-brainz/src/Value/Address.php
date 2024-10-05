<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

use MusicBrainz\Value;

/**
 * The address describes the location of the place using the standard addressing format for the country it is located in.
 *
 * @see https://musicbrainz.org/doc/Place
 */
class Address implements Value
{
    /**
     * The MusicBrainz Identifier (MBID)
     *
     * @var string
     */
    private string $address;

    /**
     * Constructs an address.
     *
     * @param string $address An address in the standard addressing format for the country it is located in
     */
    public function __construct(string $address = '')
    {
        $this->address = $address;
    }

    /**
     * Returns the address as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->address;
    }
}
