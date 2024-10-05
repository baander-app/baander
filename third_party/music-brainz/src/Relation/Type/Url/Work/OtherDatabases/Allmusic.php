<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work\OtherDatabases;

use MusicBrainz\Relation\Type\Url\Work\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This is used to link a work to its corresponding page on Allmusic.
 *
 * @link https://musicbrainz.org/relationship/ca9c9f46-11bd-423a-b134-9109cbebe9d7
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
