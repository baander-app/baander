<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place;

use MusicBrainz\Relation\Type\Artist\Place;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist was the owner of this place (often a studio, but sometimes also a venue).
 *
 * @link https://musicbrainz.org/relationship/6f238bfb-0108-45ad-a1da-960c919a7066
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
