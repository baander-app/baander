<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release\ManufacturedAt;

use MusicBrainz\Relation\Type\Place\Release\ManufacturedAt;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was pressed at.
 *
 * @link https://musicbrainz.org/relationship/eabf5903-8467-4329-b905-7504336e8241
 */
class PressedAt extends ManufacturedAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('pressed at');
    }
}
