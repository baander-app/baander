<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the place it was mixed at.
 *
 * @link https://musicbrainz.org/relationship/11d74801-1493-4a5d-bc0f-4ddc537acddb
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
