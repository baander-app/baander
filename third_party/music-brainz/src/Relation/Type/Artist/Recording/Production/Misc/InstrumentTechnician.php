<?php

declare(strict_types=1);

namespace MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;

use MusicBrainz\Relation\Type\Artist\Recording\Production\Misc;
use MusicBrainz\Value\Name;

/**
 * Indicates the instrument technician for this recording. Use also for "piano tuner" credits and other similar ones.
 *
 * @link https://musicbrainz.org/relationship/e88fefc3-042b-4f28-83af-6a79793b630b
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
