<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Recording;

use MusicBrainz\Relation\Type\Label\Recording;
use MusicBrainz\Value\Name;

/**
 * This indicates that the label performed a role not covered by other relationship types.
 *
 * @link https://musicbrainz.org/relationship/57935ae5-9f21-47bc-9854-0fa5d1a56696
 */
class Misc extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('misc');
    }
}
