<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Work;

use MusicBrainz\Relation\Type\Area\Work;
use MusicBrainz\Value\Name;

/**
 * Designates that a work is or was the anthem for an area
 *
 * @link https://musicbrainz.org/relationship/536b4ee4-bb2d-3b6f-a3f1-082f22e5b17d
 */
class Anthem extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('anthem');
    }
}
