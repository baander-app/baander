<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Series;

use MusicBrainz\Relation\Type\Series\Series;
use MusicBrainz\Value\Name;

/**
 * Links a recording, release or release group series to the event series (tour, residency, etc.) it was recorded during.
 *
 * @link https://musicbrainz.org/relationship/b738cb29-0c23-4c1e-9bba-4934ea0d3f7f
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
