<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Place\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links a place to its corresponding page at setlist.fm.
 *
 * @link https://musicbrainz.org/relationship/751e8fb1-ed8d-4a94-b71b-a38065054f5d
 */
class Setlistfm extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('setlistfm');
    }
}
