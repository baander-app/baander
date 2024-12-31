<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;

use MusicBrainz\Relation\Type\ReleaseGroup\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This is used to link a release group to its corresponding page on Allmusic.
 *
 * @link https://musicbrainz.org/relationship/a50a1d20-2b20-4d2c-9a29-eb771dd78386
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
