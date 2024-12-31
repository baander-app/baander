<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Composition\Writer;

use MusicBrainz\Relation\Type\Artist\Work\Composition\Writer;
use MusicBrainz\Value\Name;

/**
 * Indicates the composer for this work, that is, the artist who wrote the music (not necessarily the lyrics).
 *
 * @link https://musicbrainz.org/relationship/d59d99ea-23d4-4a80-b066-edca32ee158f
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
