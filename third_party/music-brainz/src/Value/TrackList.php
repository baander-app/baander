<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of tracks
 */
class TrackList extends ValueList
{
    /**
     * Constructs a list of tracks.
     *
     * @param array $tracks An array track arrays
     */
    public function __construct(array $tracks = [])
    {
        parent::__construct(
            array_map(
                function ($track) {
                    return new Track($track);
                },
                $tracks
            )
        );
    }

    /**
     * Returns the class name of the list elements.
     *
     * @return string
     */
    public static function getType(): string
    {
        return Track::class;
    }
}
