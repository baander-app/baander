<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Release\Release;

use MusicBrainz\Relation\Type\Release\Release;
use MusicBrainz\Value\Name;

/**
 * This indicates that a release was released in support of another release. This allows a release to be linked to its supporting singles, EPs, and remix releases. A 'supporting release' is one which is released to increase sales of an album or to create publicity for an album.
 *
 * @link https://musicbrainz.org/relationship/7ad3c97e-e524-4d9a-a384-2b1407f4939b
 */
class SupportingRelease extends Release
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('supporting release');
    }
}
