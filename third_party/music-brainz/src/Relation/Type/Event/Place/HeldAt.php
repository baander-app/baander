<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Place;

use MusicBrainz\Relation\Type\Event\Place;
use MusicBrainz\Value\Name;

/**
 * Links an event to the place where it was held.
 *
 * @link https://musicbrainz.org/relationship/e2c6f697-07dc-38b1-be0b-83d740165532
 */
class HeldAt extends Place
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('held at');
    }
}
