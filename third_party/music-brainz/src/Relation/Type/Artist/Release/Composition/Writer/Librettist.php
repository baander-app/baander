<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Composition\Writer;

use MusicBrainz\Relation\Type\Artist\Release\Composition\Writer;
use MusicBrainz\Value\Name;

/**
 * Indicates the librettist for this release.
 *
 * @link https://musicbrainz.org/relationship/dd182715-ca2b-4e4b-80b1-d21742fda0dc
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
