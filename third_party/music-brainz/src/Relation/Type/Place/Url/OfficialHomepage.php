<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Url;

use MusicBrainz\Relation\Type\Place\Url;
use MusicBrainz\Value\Name;

/**
 * Indicates the official homepage for a place.
 *
 * @link https://musicbrainz.org/relationship/696b79da-7e45-40e6-a9d4-b31438eb7e5d
 */
class OfficialHomepage extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('official homepage');
    }
}
