<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Label\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links a label to its corresponding page at Last.fm
 *
 * @link https://musicbrainz.org/relationship/e3390a1d-3083-4bc9-9295-aff9da18612c
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
