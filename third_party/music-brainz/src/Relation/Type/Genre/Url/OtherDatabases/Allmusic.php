<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Genre\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Genre\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This is used to link a genre to its corresponding page on Allmusic.
 *
 * @link https://musicbrainz.org/relationship/6da144de-911b-49c5-81eb-bd8303b3f6b4
 */
class Allmusic extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('allmusic');
    }
}
