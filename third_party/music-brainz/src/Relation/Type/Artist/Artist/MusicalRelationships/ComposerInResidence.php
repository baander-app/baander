<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;

use MusicBrainz\Relation\Type\Artist\Artist\MusicalRelationships;
use MusicBrainz\Value\Name;

/**
 * This links a group (often an orchestra) to a composer who has a composer-in-residence position with the group.
 *
 * @link https://musicbrainz.org/relationship/094b1ddf-3df3-4fb9-8b01-cfd28e45da80
 */
class ComposerInResidence extends MusicalRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('composer-in-residence');
    }
}
