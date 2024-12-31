<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release;

use MusicBrainz\Relation\Type\Place\Release;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was engineered at.
 *
 * @link https://musicbrainz.org/relationship/b35aae66-5578-41d1-b34b-1c9b1897ad49
 */
class EngineeredAt extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('engineered at');
    }
}
