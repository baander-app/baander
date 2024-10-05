<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This links an artist's performance name (a stage name or alias) with their legal name (or a more well know performance name if the legal name is unknown).
 *
 * @link https://musicbrainz.org/relationship/dd9886f2-1dfe-4270-97db-283f6839a666
 */
class IsPerson extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('is person');
    }
}
