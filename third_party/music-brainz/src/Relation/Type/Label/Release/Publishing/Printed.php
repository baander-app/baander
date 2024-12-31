<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing;

use MusicBrainz\Relation\Type\Label\Release\Publishing;
use MusicBrainz\Value\Name;

/**
 * This indicates the organization that printed a release. This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/f723f293-27b1-4c90-a623-6bacd0534465
 */
class Printed extends Publishing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('printed');
    }
}
