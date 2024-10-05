<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Engineer;
use MusicBrainz\Value\Name;

/**
 * This relationship type is deprecated and should not be used.
 *
 * @link https://musicbrainz.org/relationship/30adb2d7-dbcc-4393-9230-2098510ce3c1
 */
class Mastering extends Engineer
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('mastering');
    }
}
