<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\ContractedTasks;

use MusicBrainz\Relation\Type\Label\Release\ContractedTasks;
use MusicBrainz\Value\Name;

/**
 * Use this relationship for credits like “Release was manufactured by X for Label”.
 *
 * @link https://musicbrainz.org/relationship/4d79a32f-7295-4b39-882f-cc6371e5493a
 */
class ManufacturedFor extends ContractedTasks
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('manufactured for');
    }
}
