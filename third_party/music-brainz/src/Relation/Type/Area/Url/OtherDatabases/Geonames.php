<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Area\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Geonames page for this area.
 *
 * @link https://musicbrainz.org/relationship/c52f14c0-e9ac-4a8a-8f7a-c47328de168f
 */
class Geonames extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('geonames');
    }
}
