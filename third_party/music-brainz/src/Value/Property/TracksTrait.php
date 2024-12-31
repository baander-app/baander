<?php

declare(strict_types=1);

namespace MusicBrainz\Value\Property;

use MusicBrainz\Helper\ArrayAccess;
use MusicBrainz\Value\Track;
use MusicBrainz\Value\TrackList;

use function is_null;

/**
 * Provides a getter for a list of tracks.
 */
trait TracksTrait
{
    /**
     * A list of tracks
     *
     * @var Track[]|TrackList
     */
    private TrackList $tracks;

    /**
     * Returns a list of tracks.
     *
     * @return Track[]|TrackList
     */
    public function getTracks(): TrackList
    {
        return $this->tracks;
    }

    /**
     * Sets the list of tracks by extracting it from a given input array.
     *
     * @param array $input An array returned by the webservice
     *
     * @return void
     */
    private function setTracksFromArray(array $input): void
    {
        $this->tracks = is_null($tracks = ArrayAccess::getArray($input, 'tracks'))
            ? new TrackList()
            : new TrackList($tracks);
    }
}
