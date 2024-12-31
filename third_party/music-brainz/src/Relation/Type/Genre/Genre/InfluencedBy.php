<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Genre;

use MusicBrainz\Relation\Type\Genre\Genre;
use MusicBrainz\Value\Name;

/**
 * This indicates that a genre has influences of another, but is not connected to it enough to be a subgenre of it.
 *
 * @link https://musicbrainz.org/relationship/59117855-52db-4371-8dd3-87a16f285499
 */
class InfluencedBy extends Genre
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('influenced by');
    }
}
