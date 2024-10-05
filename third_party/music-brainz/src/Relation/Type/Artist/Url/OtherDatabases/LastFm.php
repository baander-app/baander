<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an artist to its corresponding page at Last.fm.
 *
 * @link https://musicbrainz.org/relationship/08db8098-c0df-4b78-82c3-c8697b4bba7f
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
