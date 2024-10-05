<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Url;

use MusicBrainz\Relation\Type\Series\Url;
use MusicBrainz\Value\Name;

/**
 * Indicates a page with an official schedule for an event series.
 *
 * @link https://musicbrainz.org/relationship/ac9d08ef-c794-48b9-aadb-4d2944a0b6ed
 */
class Schedule extends Url
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('schedule');
    }
}
