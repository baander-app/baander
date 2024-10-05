<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Url;

use MusicBrainz\Relation\Type\Event\Url;
use MusicBrainz\Value\Name;

/**
 * This relationship type links an event to a promotional poster for the event.
 *
 * @link https://musicbrainz.org/relationship/ca7ada80-bf87-4422-9671-ec0a22733acc
 */
class Poster extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('poster');
    }
}
