<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Value\ArtistType;

/**
 * Provides a getter for the artist type.
 */
trait ArtistTypeTrait
{
    /**
     * The type is used to state whether an artist is a person, a group, or something else.
     *
     * @var ArtistType
     */
    private ArtistType $artistType;

    /**
     * Returns the artist type.
     *
     * @return ArtistType
     */
    public function getArtistType(): ArtistType
    {
        return $this->artistType;
    }

    /**
     * Sets the artist type by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     *
     * @return void
     */
    private function setArtistTypeFromArray(array $input): void
    {
        $this->artistType = new ArtistType($input);
    }
}
