<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup;

use MusicBrainz\Relation\Type\ReleaseGroup\ReleaseGroup;
use MusicBrainz\Value\Name;

/**
 * This indicates that a single or EP release group includes at least one track taken from an album release group. This allows a release group to be linked to its associated singles and EPs.
 *
 * @link https://musicbrainz.org/relationship/fcf680a9-6871-4519-8c4b-8c6549575b35
 */
class SingleFrom extends ReleaseGroup
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('single from');
    }
}
