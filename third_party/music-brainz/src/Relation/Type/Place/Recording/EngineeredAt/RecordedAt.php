<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the place it was recorded at.
 *
 * @link https://musicbrainz.org/relationship/ad462279-14b0-4180-9b58-571d0eef7c51
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
