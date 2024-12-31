<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Recording\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This is used to link a recording to its corresponding page in the SecondHandSongs database.
 *
 * @link https://musicbrainz.org/relationship/a98fb02f-f289-4778-b34e-2625d922e28f
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
