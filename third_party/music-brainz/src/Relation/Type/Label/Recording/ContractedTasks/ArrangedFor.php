<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Recording\ContractedTasks;

use MusicBrainz\Relation\Type\Label\Recording\ContractedTasks;
use MusicBrainz\Value\Name;

/**
 * Use this relationship for credits like “Recording was arranged by X for Label”.
 *
 * @link https://musicbrainz.org/relationship/1a26bf37-1f34-4c9a-97d5-b96bc49e9153
 */
class ArrangedFor extends ContractedTasks
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('arranged for');
    }
}
