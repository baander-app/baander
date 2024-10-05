<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place;

use MusicBrainz\Relation\Type\Artist\Place;
use MusicBrainz\Value\Name;

/**
 * This relationship type is only used for grouping other relationship types.
 *
 * @link https://musicbrainz.org/relationship/9e338897-2b63-40a8-b7c1-fca0dbbaa3cd
 */
class ResidencePosition extends Place
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('residence position');
    }
}
