<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\ContractedTasks;

use MusicBrainz\Relation\Type\Label\Release\ContractedTasks;
use MusicBrainz\Value\Name;

/**
 * Use this relationship for credits like “Release was arranged by X for Label”.
 *
 * @link https://musicbrainz.org/relationship/c395de60-b84b-4a09-99b2-30b222fda04e
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
