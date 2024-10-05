<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Event;

use MusicBrainz\Relation\Type\Artist\Event;
use MusicBrainz\Value\Name;

/**
 * Links an event to (one of) its main performer(s).
 *
 * @link https://musicbrainz.org/relationship/936c7c95-3156-3889-a062-8a0cd57f8946
 */
class MainPerformer extends Event
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('main performer');
    }
}
