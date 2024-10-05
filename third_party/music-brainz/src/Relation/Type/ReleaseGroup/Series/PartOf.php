<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Series;

use MusicBrainz\Relation\Type\ReleaseGroup\Series;
use MusicBrainz\Value\Name;

/**
 * Indicates that the release group is part of a series.
 *
 * @link https://musicbrainz.org/relationship/01018437-91d8-36b9-bf89-3f885d53b5bd
 */
class PartOf extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('part of');
    }
}
