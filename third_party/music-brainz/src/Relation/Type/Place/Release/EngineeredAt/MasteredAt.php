<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Release\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was mastered at.
 *
 * @link https://musicbrainz.org/relationship/5d075afa-6bb8-4327-9528-e3e4d3d68f49
 */
class MasteredAt extends EngineeredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mastered at');
    }
}
