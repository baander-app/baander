<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Place\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This points to the VIAF page for this place. VIAF is an international project to make a common authority file available to libraries across the world. An authority file is similar to an MBID for libraries (more information on Wikipedia).
 *
 * @link https://musicbrainz.org/relationship/49a08641-0aed-4e10-8311-ec220b8c50ad
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
