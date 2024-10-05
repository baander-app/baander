<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing;

use MusicBrainz\Relation\Type\Label\Release\Publishing;
use MusicBrainz\Value\Name;

/**
 * This indicates the organization that distributes (or contracts out distribution). This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/4f89b0a1-e135-41e4-94a7-e3d2a95f31a1
 */
class Distributed extends Publishing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('distributed');
    }
}
