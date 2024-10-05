<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Event;

use MusicBrainz\Relation\Type\Artist\Event;
use MusicBrainz\Value\Name;

/**
 * Links an event to an artist who was a VJ during it, either as the background for someone else’s musical performance or as its own performance.
 *
 * @link https://musicbrainz.org/relationship/fb71a94c-9b20-4377-a78c-46dfe6d095ef
 */
class VJ extends Event
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('VJ');
    }
}
