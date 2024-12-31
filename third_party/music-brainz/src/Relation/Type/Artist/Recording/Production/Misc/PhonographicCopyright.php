<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This relationship indicates the artist is the phonographic copyright holder for this recording, usually indicated with a ℗ symbol.
 *
 * @link https://musicbrainz.org/relationship/7fd5fbc0-fbf4-4d04-be23-417d50a4dc30
 */
class PhonographicCopyright extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('phonographic copyright');
    }
}
