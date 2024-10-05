<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Series;

use MusicBrainz\Relation\Type\Recording\Series;
use MusicBrainz\Value\Name;

/**
 * Links a recording to the event series (tour, residency, etc.) it was recorded during.
 *
 * @link https://musicbrainz.org/relationship/1640c657-d614-47cf-8161-b93809c9b88b
 */
class RecordedDuring extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('recorded during');
    }
}
