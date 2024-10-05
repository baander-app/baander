<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Composition\Writer;

use MusicBrainz\Relation\Type\Artist\Release\Composition\Writer;
use MusicBrainz\Value\Name;

/**
 * Indicates the composer for this release, that is, the artist who wrote the music (not necessarily the lyrics).
 *
 * @link https://musicbrainz.org/relationship/01ce32b0-d873-4baa-8025-714b45c0c754
 */
class Composer extends Writer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('composer');
    }
}
