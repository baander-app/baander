<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Series;

use MusicBrainz\Relation\Type\Event\Series;
use MusicBrainz\Value\Name;

/**
 * Indicates that the event is part of a series.
 *
 * @link https://musicbrainz.org/relationship/707d947d-9563-328a-9a7d-0c5b9c3a9791
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
