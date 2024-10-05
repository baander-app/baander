<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Series;

use MusicBrainz\Relation\Type\Place\Series;
use MusicBrainz\Value\Name;

/**
 * Indicates the location a run or residency was held at.
 *
 * @link https://musicbrainz.org/relationship/0f5c1077-bea7-4a00-ad65-89dd1972fe76
 */
class HeldAt extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('held at');
    }
}
