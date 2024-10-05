<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Place\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/c3ddb53d-a7df-4486-8cc7-c1b7baec994e
 */
class LastFm extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('last.fm');
    }
}
