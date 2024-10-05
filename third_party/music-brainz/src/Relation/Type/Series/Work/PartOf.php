<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Series\Work;

use MusicBrainz\Relation\Type\Series\Work;
use MusicBrainz\Value\Name;

/**
 * Indicates that the work is part of a series.
 *
 * @link https://musicbrainz.org/relationship/b0d44366-cdf0-3acb-bee6-0f65a77a6ef0
 */
class PartOf extends Work
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('part of');
    }
}
