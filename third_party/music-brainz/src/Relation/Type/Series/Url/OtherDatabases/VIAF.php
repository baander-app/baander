<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url\OtherDatabases;

use MusicBrainz\Relation\Type\Series\Url\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This points to the VIAF page for this series. VIAF is an international project to make a common authority file available to libraries across the world. An authority file is similar to an MBID for libraries (more information on Wikipedia).
 *
 * @link https://musicbrainz.org/relationship/67764397-d154-4f9a-8aa8-cbc4de4df5b8
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
