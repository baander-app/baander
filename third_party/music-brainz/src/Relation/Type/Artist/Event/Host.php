<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Event;

use MusicBrainz\Relation\Type\Artist\Event;
use MusicBrainz\Value\Name;

/**
 * Links an event to its host/MC. Event hosts usually do introductions to the show or each song.
 *
 * @link https://musicbrainz.org/relationship/76e8523e-567c-3e44-a302-3c75e601fcc2
 */
class Host extends Event
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('host');
    }
}
