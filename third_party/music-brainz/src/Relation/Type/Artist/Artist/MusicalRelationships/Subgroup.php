<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This links a subgroup to the group from which it was created.
 *
 * @link https://musicbrainz.org/relationship/7802f96b-d995-4ce9-8f70-6366faad758e
 */
class Subgroup extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('subgroup');
    }
}
