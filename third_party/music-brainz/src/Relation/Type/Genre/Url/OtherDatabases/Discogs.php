<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Genre\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This is used to link the Discogs page for this genre/style.
 *
 * @link https://musicbrainz.org/relationship/4c8510c9-1dc2-49b9-9693-27bdc5cc8311
 */
class Discogs extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('discogs');
    }
}
