<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This describes a situation where an artist (generally a group) changed its name, leading to the start of a new project.
 *
 * @link https://musicbrainz.org/relationship/9752bfdf-13ca-441a-a8bc-18928c600c73
 */
class ArtistRename extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('artist rename');
    }
}
