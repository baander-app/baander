<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Release\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This is used to link a release to its corresponding page in the SecondHandSongs database.
 *
 * @link https://musicbrainz.org/relationship/0e555925-1b7d-475c-9b25-b9c349dcc3f3
 */
class Secondhandsongs extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('secondhandsongs');
    }
}
