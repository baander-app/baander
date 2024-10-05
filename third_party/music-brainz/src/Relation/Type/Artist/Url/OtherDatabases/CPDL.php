<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This links an artist to its page in CPDL.
 *
 * @link https://musicbrainz.org/relationship/991d7d60-01ee-41de-9b62-9ef3f86c2447
 */
class CPDL extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('CPDL');
    }
}
