<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Recording;

use MusicBrainz\Relation\Type\Place\Recording;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the place it was arranged at.
 *
 * @link https://musicbrainz.org/relationship/ce8dbff2-0f07-45f3-8b7c-2ea40d82cbfe
 */
class ArrangedAt extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('arranged at');
    }
}
