<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Event\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an event to its corresponding page at VGMdb. VGMdb is a community project dedicated to cataloguing the music of video games and anime.
 *
 * @link https://musicbrainz.org/relationship/5d3e0348-71a8-3dc1-b847-3a8f1d5de688
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
