<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Place;

use MusicBrainz\Relation\Type\Label\Place;
use MusicBrainz\Value\Name;

/**
 * This indicates the label / organization was the owner of this place (often a studio, but sometimes also a venue).
 *
 * @link https://musicbrainz.org/relationship/06829429-0f20-4c00-aa3d-871fde07d8c4
 */
class Owner extends Place
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('owner');
    }
}
