<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\ContractedTasks;

use MusicBrainz\Relation\Type\Label\Release\ContractedTasks;
use MusicBrainz\Value\Name;

/**
 * Use this relationship for credits like "Release was mixed by X for Label"
 *
 * @link https://musicbrainz.org/relationship/17976e73-51a2-4174-9004-3baaba6b78c6
 */
class MixedFor extends ContractedTasks
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mixed for');
    }
}
