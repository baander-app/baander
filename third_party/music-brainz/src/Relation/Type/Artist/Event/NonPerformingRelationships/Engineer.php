<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Event\NonPerformingRelationships;

use MusicBrainz\Relation\Type\Artist\Event\NonPerformingRelationships;
use MusicBrainz\Value\Name;

/**
 * Links an event to an engineer or sound technician who worked on it.
 *
 * @link https://musicbrainz.org/relationship/19d9339e-04d7-4d59-8a16-9fea1263bbd7
 */
class Engineer extends NonPerformingRelationships
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('engineer');
    }
}
