<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This credits an artist whose photographs are included as part of a release.
 *
 * @link https://musicbrainz.org/relationship/0b58dc9b-9c49-4b19-bb58-9c06d41c8fbf
 */
class Photography extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('photography');
    }
}
