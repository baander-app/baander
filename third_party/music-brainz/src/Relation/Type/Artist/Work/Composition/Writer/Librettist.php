<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Composition\Writer;

use MusicBrainz\Relation\Type\Artist\Work\Composition\Writer;
use MusicBrainz\Value\Name;

/**
 * Indicates the librettist for this work.
 *
 * @link https://musicbrainz.org/relationship/7474ab81-486f-40b5-8685-3a4f8ea624cb
 */
class Librettist extends Writer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('librettist');
    }
}
