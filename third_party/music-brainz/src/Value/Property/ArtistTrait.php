<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Artist;

use function is_null;

/**
 * Provides a getter for the artist.
 */
trait ArtistTrait
{
    /**
     * The artist
     *
     * @var Artist
     */
    private Artist $artist;

    /**
     * Returns the artist.
     *
     * @return Artist
     */
    public function getArtist(): Artist
    {
        return $this->artist;
    }

    /**
     * Sets the artist by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setArtistFromArray(array $input, ?string $key = 'artist'): void
    {
        if (is_null($key)) {
            $this->artist = new Artist($input);

            return;
        }

        $this->artist = is_null($artist = ArrayAccess::getArray($input, $key))
            ? new Artist()
            : new Artist($artist);
    }
}
