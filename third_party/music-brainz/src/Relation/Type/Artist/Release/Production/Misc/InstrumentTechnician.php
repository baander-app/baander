<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Release\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Release\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * Indicates the instrument technician for this release. Use also for "piano tuner" credits and other similar ones.
 *
 * @link https://musicbrainz.org/relationship/bd780597-0b67-4b97-a4a0-671a70e1182d
 */
class InstrumentTechnician extends Misc
{
    /**
     * Returns the name of the relation.
     *
     * @return Name
     */
    public static function getRelationName(): Name
    {
        return new Name('instrument technician');
    }
}
