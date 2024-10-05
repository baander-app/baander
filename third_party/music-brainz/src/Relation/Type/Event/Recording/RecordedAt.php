<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Event\Recording;

use MusicBrainz\Relation\Type\Event\Recording;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the event it was recorded at.
 *
 * @link https://musicbrainz.org/relationship/b06e6732-2603-47d3-8a49-9f589b430483
 */
class RecordedAt extends Recording
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('recorded at');
    }
}
