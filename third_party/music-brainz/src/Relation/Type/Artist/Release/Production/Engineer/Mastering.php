<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * Indicates the mastering engineer for this work.
 *
 * @link https://musicbrainz.org/relationship/84453d28-c3e8-4864-9aae-25aa968bcf9e
 */
class Mastering extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mastering');
    }
}
