<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Recording\Work;

use MusicBrainz\Relation\Type\Recording\Work;
use MusicBrainz\Value\Name;

/**
 * This is used to link works to their recordings.
 *
 * @link https://musicbrainz.org/relationship/a3005666-a872-32c3-ad06-98af558e99b0
 */
class Performance extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('performance');
    }
}
