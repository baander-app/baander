<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This indicates that a person or firm provided legal representation for the release.
 *
 * @link https://musicbrainz.org/relationship/1a900189-53ba-442a-9406-49c43ddecb3f
 */
class LegalRepresentation extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('legal representation');
    }
}
