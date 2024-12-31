<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer responsible for transferring a release, for example from an old tape to digital.
 *
 * @link https://musicbrainz.org/relationship/9fe3ca27-f9df-4e06-ab2d-84947f3f897e
 */
class Transfer extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('transfer');
    }
}
