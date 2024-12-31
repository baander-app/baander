<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Series;

use MusicBrainz\Relation\Type\Recording\Series;
use MusicBrainz\Value\Name;

/**
 * Indicates that the recording is part of a series.
 *
 * @link https://musicbrainz.org/relationship/ea6f0698-6782-30d6-b16d-293081b66774
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
