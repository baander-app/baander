<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing;

use MusicBrainz\Relation\Type\Label\Release\Publishing;
use MusicBrainz\Value\Name;

/**
 * This indicates the organization which releases a release. This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/25858332-bf31-4ad6-85b6-6a3bccebf02e
 */
class Published extends Publishing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('published');
    }
}
