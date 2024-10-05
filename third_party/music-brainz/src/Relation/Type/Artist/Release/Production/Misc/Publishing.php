<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * Indicates the publisher of this release. This is not the same concept as the record label.
 *
 * @link https://musicbrainz.org/relationship/7a573a01-8815-44db-8e30-693faa83fbfa
 */
class Publishing extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('publishing');
    }
}
