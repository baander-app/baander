<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Place\Work\WrittenAt;

use MusicBrainz\Relation\Type\Place\Work\WrittenAt;
use MusicBrainz\Value\Name;

/**
 * This links a work with the place it was revised at.
 *
 * @link https://musicbrainz.org/relationship/31fb8bbc-5458-4b5a-8747-2b8f373fe0c1
 */
class RevisedAt extends WrittenAt
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('revised at');
    }
}
