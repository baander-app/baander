<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\ContractedTasks;

use MusicBrainz\Relation\Type\Label\Release\ContractedTasks;
use MusicBrainz\Value\Name;

/**
 * Use this relationship for credits like “Release was produced by X for Label”.
 *
 * @link https://musicbrainz.org/relationship/a4c3fb3a-66f8-4362-9507-bb846fe86b64
 */
class ProducedFor extends ContractedTasks
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('produced for');
    }
}
