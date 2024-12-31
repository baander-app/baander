<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Series;

use MusicBrainz\Relation\Type\Series\Series;
use MusicBrainz\Value\Name;

/**
 * Links a series to another series that exists within it.
 *
 * @link https://musicbrainz.org/relationship/a3af4c16-de83-4d63-b9b8-77e074c9babe
 */
class Subseries extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('subseries');
    }
}
