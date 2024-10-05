<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place;

use MusicBrainz\Relation\Type\Artist\Place;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist that inspired this place’s name.
 *
 * @link https://musicbrainz.org/relationship/8a3994fd-71ec-4443-9882-2192801241f2
 */
class NamedAfter extends Place
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('named after');
    }
}
