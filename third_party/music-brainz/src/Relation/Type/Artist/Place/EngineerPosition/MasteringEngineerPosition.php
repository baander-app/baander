<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Place\EngineerPosition;

use MusicBrainz\Relation\Type\Artist\Place\EngineerPosition;
use MusicBrainz\Value\Name;

/**
 * Describes the fact a person was contracted by a place as a mastering engineer.
 *
 * @link https://musicbrainz.org/relationship/98e2ad89-6641-4336-913d-db1515aaabcb
 */
class MasteringEngineerPosition extends EngineerPosition
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mastering engineer position');
    }
}
