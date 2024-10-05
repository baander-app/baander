<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an artist to its corresponding page at VGMdb. VGMdb is a community project dedicated to cataloguing the music of video games and anime.
 *
 * @link https://musicbrainz.org/relationship/0af15ab3-c615-46d6-b95b-a5fcd2a92ed9
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
