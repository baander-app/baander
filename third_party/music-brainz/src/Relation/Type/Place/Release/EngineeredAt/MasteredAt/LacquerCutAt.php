<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\EngineeredAt\MasteredAt;

use MusicBrainz\Relation\Type\Place\Release\EngineeredAt\MasteredAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place where the lacquer cutting took place.
 *
 * @link https://musicbrainz.org/relationship/e87ae6d1-4453-4e73-aa29-86a6badc1f1d
 */
class LacquerCutAt extends MasteredAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('lacquer cut at');
    }
}
