<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place;

use MusicBrainz\Relation\Type\Artist\Place;
use MusicBrainz\Value\Name;

/**
 * This is used to link an artist to its primary concert venue.
 *
 * @link https://musicbrainz.org/relationship/fff4640a-0819-49e9-92c5-1e3b5134fd95
 */
class PrimaryConcertVenue extends Place
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('primary concert venue');
    }
}
