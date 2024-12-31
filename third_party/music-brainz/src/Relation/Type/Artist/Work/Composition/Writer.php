<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Work\Composition;

use MusicBrainz\Relation\Type\Artist\Work\Composition;
use MusicBrainz\Value\Name;

/**
 * This relationship is used to link a work to the artist responsible for writing the music and/or the words (lyrics, libretto, etc.), when no more specific information is available. If possible, the more specific composer, lyricist and/or librettist types should be used, rather than this relationship type.
 *
 * @link https://musicbrainz.org/relationship/a255bca1-b157-4518-9108-7b147dc3fc68
 */
class Writer extends Composition
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('writer');
    }
}
