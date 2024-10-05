<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release;

use MusicBrainz\Relation\Type\Place\Release;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was produced at.
 *
 * @link https://musicbrainz.org/relationship/1c73465d-9bf7-41f4-bcb6-fb1852ffa64c
 */
class ProducedAt extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('produced at');
    }
}
