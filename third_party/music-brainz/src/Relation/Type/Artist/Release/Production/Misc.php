<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production;

use MusicBrainz\Relation\Type\Artist\Release\Production;
use MusicBrainz\Value\Name;

/**
 * This indicates that the artist performed a role not covered by other relationship types.
 *
 * @link https://musicbrainz.org/relationship/0b63af5e-85b2-4891-8234-bddab251399d
 */
class Misc extends Production
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
