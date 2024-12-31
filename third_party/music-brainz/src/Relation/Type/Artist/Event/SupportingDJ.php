<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Event;

use MusicBrainz\Relation\Type\Artist\Event;
use MusicBrainz\Value\Name;

/**
 * Links an event to a DJ that appeared in a supporting role (such as DJing between artists, or closing the night after a concert).
 *
 * @link https://musicbrainz.org/relationship/2c5c92da-259c-42fc-ac1f-53d9dda2d6d0
 */
class SupportingDJ extends Event
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('supporting DJ');
    }
}
