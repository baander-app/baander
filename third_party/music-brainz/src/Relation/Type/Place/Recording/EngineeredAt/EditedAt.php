<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the place it was edited at.
 *
 * @link https://musicbrainz.org/relationship/069c1cf0-a9b2-448e-8486-1eced48b48f9
 */
class EditedAt extends EngineeredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('edited at');
    }
}
