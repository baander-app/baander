<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Label\Ownership;

use MusicBrainz\Relation\Type\Artist\Label\Ownership;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist was the owner of this label.
 *
 * @link https://musicbrainz.org/relationship/610fa594-eeaa-407b-a9f1-49f509ab5559
 */
class Owner extends Ownership
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('owner');
    }
}
