<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Release;

use MusicBrainz\Relation\Type\Area\Release;
use MusicBrainz\Value\Name;

/**
 * Links a release to the area it was manufactured ("made") in.
 *
 * @link https://musicbrainz.org/relationship/c0998b44-aa21-4d27-be79-2ff64205cecd
 */
class ManufacturedIn extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('manufactured in');
    }
}
