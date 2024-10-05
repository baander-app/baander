<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Release\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This relationship type links a release to its corresponding page VGMdb. VGMdb is a community project dedicated to cataloguing the music of video games and anime.
 *
 * @link https://musicbrainz.org/relationship/6af0134a-df6a-425a-96e2-895f9cd342ba
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
