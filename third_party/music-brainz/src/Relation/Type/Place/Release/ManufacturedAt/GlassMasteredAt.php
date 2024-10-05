<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\ManufacturedAt;

use MusicBrainz\Relation\Type\Place\Release\ManufacturedAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place where the glass master for pressing was made. Not the same as mastered!
 *
 * @link https://musicbrainz.org/relationship/d3709732-c488-45e9-9b46-ca15c50de552
 */
class GlassMasteredAt extends ManufacturedAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('glass mastered at');
    }
}
