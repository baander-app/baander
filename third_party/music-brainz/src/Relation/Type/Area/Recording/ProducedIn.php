<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Area\Recording;

use MusicBrainz\Relation\Type\Area\Recording;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the area it was produced in. Use only when the place is unknown!
 *
 * @link https://musicbrainz.org/relationship/93078fc7-6585-40a7-ab7f-6acb9da65b84
 */
class ProducedIn extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('produced in');
    }
}
