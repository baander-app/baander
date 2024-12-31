<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing;

use MusicBrainz\Relation\Type\Label\Release\Publishing;
use MusicBrainz\Value\Name;

/**
 * This indicates the organization that markets a release. This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/543a0464-52f0-4282-b8b0-080ca03395a0
 */
class Marketed extends Publishing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('marketed');
    }
}
