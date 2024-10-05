<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Artist\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This points to the VIAF page for this artist. VIAF is an international project to make a common authority file available to libraries across the world. An authority file is similar to an MBID for libraries (more information on Wikipedia).
 *
 * @link https://musicbrainz.org/relationship/e8571dcc-35d4-4e91-a577-a3382fd84460
 */
class VIAF extends OtherDatabases
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('VIAF');
    }
}
