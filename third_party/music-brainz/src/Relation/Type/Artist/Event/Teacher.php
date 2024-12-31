<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Event;

use MusicBrainz\Relation\Type\Artist\Event;
use MusicBrainz\Value\Name;

/**
 * This relationship indicates that a person was a teacher at an event (such as a masterclass).
 *
 * @link https://musicbrainz.org/relationship/e5e6a204-8f81-4b17-9b54-a73a1a6db2bb
 */
class Teacher extends Event
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('teacher');
    }
}
