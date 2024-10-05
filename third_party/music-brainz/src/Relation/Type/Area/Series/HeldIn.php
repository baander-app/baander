<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Series;

use MusicBrainz\Relation\Type\Area\Series;
use MusicBrainz\Value\Name;

/**
 * Indicates the area an event series was held at.
 *
 * @link https://musicbrainz.org/relationship/281bf307-f1e8-4a56-a7b8-fe8304bb2bf3
 */
class HeldIn extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('held in');
    }
}
