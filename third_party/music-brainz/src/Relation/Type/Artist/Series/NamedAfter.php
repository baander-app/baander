<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Series;

use MusicBrainz\Relation\Type\Artist\Series;
use MusicBrainz\Value\Name;

/**
 * This indicates the artist that inspired this series' name, for example for an award named after a musician.
 *
 * @link https://musicbrainz.org/relationship/3673c88b-988b-47c3-a82c-4116fd2b2e1e
 */
class NamedAfter extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('named after');
    }
}
