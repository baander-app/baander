<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;

use MusicBrainz\Relation\Type\Place\Recording\EngineeredAt;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the place it was remixed at.
 *
 * @link https://musicbrainz.org/relationship/88183045-b769-48ba-a55c-c58450903241
 */
class RemixedAt extends EngineeredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('remixed at');
    }
}
