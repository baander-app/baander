<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/a7e408a1-8c64-4122-9ec2-906068955187
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
