<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Release\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was transferred at (for example from an old tape to digital).
 *
 * @link https://musicbrainz.org/relationship/e4bb03d3-74e4-427a-b442-15168de80f3c
 */
class TransferredAt extends EngineeredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('transferred at');
    }
}
