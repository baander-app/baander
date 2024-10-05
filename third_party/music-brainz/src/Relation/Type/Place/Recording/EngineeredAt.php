<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Recording;

use MusicBrainz\Relation\Type\Place\Recording;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the place it was engineered at.
 *
 * @link https://musicbrainz.org/relationship/f845a95e-b2b5-4a94-9645-fc8b031ab0bd
 */
class EngineeredAt extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('engineered at');
    }
}
