<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Place\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links a place to its corresponding page at Bandsintown.
 *
 * @link https://musicbrainz.org/relationship/0e41b9de-20d8-4d1a-869d-7018e1045439
 */
class Bandsintown extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('bandsintown');
    }
}
