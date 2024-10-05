<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Release\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was mixed at.
 *
 * @link https://musicbrainz.org/relationship/8ebfc2f6-0ac7-40f6-b03e-67fe3428f5d4
 */
class MixedAt extends EngineeredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mixed at');
    }
}
