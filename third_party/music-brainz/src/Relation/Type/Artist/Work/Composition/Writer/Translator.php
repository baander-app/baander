<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Composition\Writer;

use MusicBrainz\Relation\Type\Artist\Work\Composition\Writer;
use MusicBrainz\Value\Name;

/**
 * Indicates the person who translated the lyrics/libretto for this work.
 *
 * @link https://musicbrainz.org/relationship/da6c5d8a-ce13-474d-9375-61feb29039a5
 */
class Translator extends Writer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('translator');
    }
}
