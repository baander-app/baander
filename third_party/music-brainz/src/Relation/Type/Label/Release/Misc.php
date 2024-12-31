<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release;

use MusicBrainz\Relation\Type\Label\Release;
use MusicBrainz\Value\Name;

/**
 * This indicates that the label performed a role not covered by other relationship types.
 *
 * @link https://musicbrainz.org/relationship/2266eb23-5fab-4aa3-8d2c-ad6d42df8568
 */
class Misc extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('misc');
    }
}
