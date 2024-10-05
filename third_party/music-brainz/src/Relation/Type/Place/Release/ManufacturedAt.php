<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Release;

use MusicBrainz\Relation\Type\Place\Release;
use MusicBrainz\Value\Name;

/**
 * Links a release to the place it was manufactured at. Use a more specific credit if available
 *
 * @link https://musicbrainz.org/relationship/3de9787f-c148-4b4f-b4bf-cb9a2616a092
 */
class ManufacturedAt extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('manufactured at');
    }
}
