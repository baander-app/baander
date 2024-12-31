<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Release\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This describes an engineer responsible for using a mixing console to mix a recorded track into a single piece of music suitable for release. For remixing, see remixer.
 *
 * @link https://musicbrainz.org/relationship/6cc958c0-533b-4540-a281-058fbb941890
 */
class Mix extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mix');
    }
}
