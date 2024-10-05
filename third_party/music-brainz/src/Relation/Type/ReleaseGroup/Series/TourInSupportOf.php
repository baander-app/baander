<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\ReleaseGroup\Series;

use MusicBrainz\Relation\Type\ReleaseGroup\Series;
use MusicBrainz\Value\Name;

/**
 * This links a concert tour with a release group (generally an album) the tour is connected with.
 *
 * @link https://musicbrainz.org/relationship/72e1f4d8-011b-49ab-bba4-6990981c972e
 */
class TourInSupportOf extends Series
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('tour in support of');
    }
}
