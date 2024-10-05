<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * Points to the Wikipedia page for this album.
 *
 * @link https://musicbrainz.org/relationship/6578f0e9-1ace-4095-9de8-6e517ddb1ceb
 */
class Wikipedia extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('wikipedia');
    }
}
