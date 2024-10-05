<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Area;

use MusicBrainz\Relation\Type\Area\Area;
use MusicBrainz\Value\Name;

/**
 * Designates that one area is contained by another.
 *
 * @link https://musicbrainz.org/relationship/de7cc874-8b1b-3a05-8272-f3834c968fb7
 */
class PartOf extends Area
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('part of');
    }
}
