<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist;

use MusicBrainz\Relation\Type\Artist\Artist;
use MusicBrainz\Value\Name;

/**
 * This relationship type is only used for grouping other relationship types.
 *
 * @link https://musicbrainz.org/relationship/92859e2a-f2e5-45fa-a680-3f62ba0beccc
 */
class MusicalRelationships extends Artist
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('musical relationships');
    }
}
