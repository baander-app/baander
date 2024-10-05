<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work\OtherDatabases;

use MusicBrainz\Relation\Type\Url\Work\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This links an entity to the appropriate listing in the Songfacts database, a user contributed database concerned with the stories behind the songs.
 *
 * @link https://musicbrainz.org/relationship/80402bbc-1aec-41d1-a5be-b599b89bc3c3
 */
class Songfacts extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('songfacts');
    }
}
