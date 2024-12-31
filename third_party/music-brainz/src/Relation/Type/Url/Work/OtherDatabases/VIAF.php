<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Url\Work\OtherDatabases;

use MusicBrainz\Relation\Type\Url\Work\OtherDatabases;
use MusicBrainz\Value\Name;

/**
 * This points to the VIAF page for this work. VIAF is an international project to make a common authority file available to libraries across the world. An authority file is similar to an MBID for libraries (more information on Wikipedia).  Note: Works in VIAF aren't very detailed. Only add links to MusicBrainz works if you're absolutely sure it's the same work.
 *
 * @link https://musicbrainz.org/relationship/b6eaef52-68a0-4b50-b875-8acd7d9212ba
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
