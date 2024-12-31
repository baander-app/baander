<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place;

use MusicBrainz\Relation\Type\Artist\Place;
use MusicBrainz\Value\Name;

/**
 * Describes the fact a person was contracted by a place as an engineer.
 *
 * @link https://musicbrainz.org/relationship/666c5ee3-b763-4b74-8f71-3456dfd3e755
 */
class EngineerPosition extends Place
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('engineer position');
    }
}
