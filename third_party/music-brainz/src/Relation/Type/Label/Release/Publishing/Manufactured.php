<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Label\Release\Publishing;

use MusicBrainz\Relation\Type\Label\Release\Publishing;
use MusicBrainz\Value\Name;

/**
 * This indicates the organization that manufactures (or contracts out manufacturing). This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/835e514a-c5bc-44f7-be7b-92452a3f5d60
 */
class Manufactured extends Publishing
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('manufactured');
    }
}
