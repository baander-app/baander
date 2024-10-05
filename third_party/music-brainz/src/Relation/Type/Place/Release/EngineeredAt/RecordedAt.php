<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Release\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was recorded at.
 *
 * @link https://musicbrainz.org/relationship/3b1fae9f-5b22-42c5-a40c-d1e5c9b90251
 */
class RecordedAt extends EngineeredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('recorded at');
    }
}
