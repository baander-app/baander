<?php

declare(strict_types=1);

namespace MusicBrainz\Value;

/**
 * A list of artists
 */
class ArtistList extends ValueList
{
    /**
     * Constructs a list of artists.
     *
     * @param array $artists An array containing information about the artists
     */
    public function __construct(array $artists = [])
    {
        parent::__construct(
            array_map(
                function ($artist) {
                    return new Artist($artist);
                },
                $artists
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
        return Artist::class;
    }
}
