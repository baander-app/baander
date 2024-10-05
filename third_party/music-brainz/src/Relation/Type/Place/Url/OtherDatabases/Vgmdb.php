<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Place\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links a place (usually a studio) to its corresponding page at VGMdb. VGMdb is a community project dedicated to cataloguing the music of video games and anime.
 *
 * @link https://musicbrainz.org/relationship/f11ffda6-d59a-45bf-9b07-74b08335b5fa
 */
class Vgmdb extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('vgmdb');
    }
}
