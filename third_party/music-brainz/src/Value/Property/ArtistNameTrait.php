<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Name;

use function is_null;

/**
 * Provides a getter for an artist name.
 */
trait ArtistNameTrait
{
    /**
     * The artist name
     *
     * @var Name
     */
    public Name $artistName;

    /**
     * Returns the artist name.
     *
     * @return Name
     */
    public function getArtistName(): Name
    {
        return $this->artistName;
    }

    /**
     * Sets the artist name by extracting it from a given input array.
     *
     * @param array  $input An array returned by the webservice
     * @param string $key   An array key. Default: 'artist'
     *
     * @return void
     */
    private function setArtistNameFromArray(array $input, string $key = 'artist'): void
    {
        $this->artistName = is_null($artistName = ArrayAccess::getString($input, $key))
            ? new Name()
            : new Name($artistName);
    }
}
