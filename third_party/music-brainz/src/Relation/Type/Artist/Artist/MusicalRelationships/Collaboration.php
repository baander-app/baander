<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This is used to specify that an artist collaborated on a short-term project, for cases where artist credits can't be used.
 *
 * @link https://musicbrainz.org/relationship/75c09861-6857-4ec0-9729-84eefde7fc86
 */
class Collaboration extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('collaboration');
    }
}
